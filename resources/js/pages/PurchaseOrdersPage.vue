<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

interface Branch {
  id: string;
  name: string;
  code: string;
}

interface Supplier {
  id: string;
  name: string;
  document: string | null;
}

interface Product {
  id: string;
  sku: string;
  name: string;
}

interface PurchaseOrderItem {
  id: string;
  product_id: string;
  quantity: number;
  unit_cost: string | number;
  total: string | number;
  notes: string | null;
  product?: Product | null;
}

interface PurchaseOrder {
  id: string;
  branch_id: string;
  supplier_id: string;
  number: string;
  status: 'draft' | 'ordered' | 'approved' | 'received' | 'cancelled';
  order_date: string;
  expected_delivery_date: string | null;
  subtotal: string | number;
  shipping_cost: string | number;
  discount: string | number;
  total: string | number;
  notes: string | null;
  branch?: Branch | null;
  supplier?: Supplier | null;
  items?: PurchaseOrderItem[];
}

interface Page {
  data: PurchaseOrder[];
  current_page: number;
  last_page: number;
}

const orders = ref<PurchaseOrder[]>([]);
const branches = ref<Branch[]>([]);
const suppliers = ref<Supplier[]>([]);
const loading = ref(false);
const cancellingId = ref<string | null>(null);
const error = ref('');
const cancelConfirmationOpen = ref(false);
const pendingCancellation = ref<PurchaseOrder | null>(null);
const selectedOrder = ref<PurchaseOrder | null>(null);
const viewModalOpen = ref(false);

const search = ref('');
const branchFilter = ref('');
const supplierFilter = ref('');
const statusFilter = ref('todos');

const columns: DataGridColumn[] = [
  { key: 'number', label: 'Número' },
  { key: 'supplier', label: 'Fornecedor' },
  { key: 'branch', label: 'Filial' },
  { key: 'order_date', label: 'Data do pedido' },
  { key: 'expected_delivery_date', label: 'Previsão de entrega' },
  { key: 'items_count', label: 'Itens' },
  { key: 'total', label: 'Valor total' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];

function formatPrice(value: string | number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value));
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const parts = value.split('-');
  if (parts.length === 3) {
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  }
  return value;
}

const statusLabels: Record<string, string> = {
  draft: 'Rascunho',
  ordered: 'Emitido',
  approved: 'Aprovado',
  received: 'Recebido',
  cancelled: 'Cancelado',
};

const statusBadgeClasses: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-700 border-slate-200',
  ordered: 'bg-blue-50 text-blue-700 border-blue-200',
  approved: 'bg-amber-50 text-amber-800 border-amber-200',
  received: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  cancelled: 'bg-red-50 text-red-700 border-red-200',
};

const rows = computed(() => orders.value.map((order) => ({
  id: order.id,
  number: order.number,
  supplier: order.supplier?.name ?? '—',
  branch: order.branch ? `${order.branch.name} (${order.branch.code})` : '—',
  order_date: formatDate(order.order_date),
  expected_delivery_date: formatDate(order.expected_delivery_date),
  items_count: order.items?.length ?? 0,
  total: formatPrice(order.total),
  status: statusLabels[order.status] ?? order.status,
  status_raw: order.status,
  order,
})));

const branchComboboxOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Todas as filiais' },
  ...branches.value.map((b) => ({ value: b.id, label: `${b.name} (${b.code})` })),
]);

const supplierComboboxOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Todos os fornecedores' },
  ...suppliers.value.map((s) => ({ value: s.id, label: s.name })),
]);

const statusComboboxOptions: ComboboxOption[] = [
  { value: 'todos', label: 'Todos os status' },
  { value: 'draft', label: 'Rascunho' },
  { value: 'ordered', label: 'Emitido' },
  { value: 'approved', label: 'Aprovado' },
  { value: 'received', label: 'Recebido' },
  { value: 'cancelled', label: 'Cancelado' },
];

function setBranchFilter(value: string | string[] | null) {
  branchFilter.value = typeof value === 'string' ? value : '';
}

function setSupplierFilter(value: string | string[] | null) {
  supplierFilter.value = typeof value === 'string' ? value : '';
}

function setStatusFilter(value: string | string[] | null) {
  statusFilter.value = typeof value === 'string' && value ? value : 'todos';
}

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadLookups() {
  const [branchesRes, suppliersRes] = await Promise.all([
    fetch('/api/v1/branches?per_page=100', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/suppliers?per_page=100', { headers: { Accept: 'application/json' } }),
  ]);
  if (!branchesRes.ok) throw await responseError(branchesRes, 'Não foi possível carregar as filiais.');
  if (!suppliersRes.ok) throw await responseError(suppliersRes, 'Não foi possível carregar os fornecedores.');

  branches.value = (await branchesRes.json() as { data: Branch[] }).data;
  suppliers.value = (await suppliersRes.json() as { data: Supplier[] }).data;
}

async function loadOrders() {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams({ per_page: '100' });
    if (search.value.trim()) params.set('search', search.value.trim());
    if (branchFilter.value) params.set('branch_id', branchFilter.value);
    if (supplierFilter.value) params.set('supplier_id', supplierFilter.value);
    if (statusFilter.value !== 'todos') params.set('status', statusFilter.value);

    let page = 1;
    let lastPage = 1;
    const all: PurchaseOrder[] = [];

    do {
      params.set('page', String(page));
      const response = await fetch(`/api/v1/purchase-orders?${params.toString()}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw await responseError(response, 'Não foi possível carregar os pedidos de compra.');
      const payload = await response.json() as Page;
      all.push(...payload.data);
      lastPage = payload.last_page;
      page += 1;
    } while (page <= lastPage);

    orders.value = all;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar pedidos de compra.';
  } finally {
    loading.value = false;
  }
}

function openViewModal(order: PurchaseOrder) {
  selectedOrder.value = order;
  viewModalOpen.value = true;
}

function closeViewModal() {
  viewModalOpen.value = false;
  selectedOrder.value = null;
}

function requestCancel(order: PurchaseOrder) {
  pendingCancellation.value = order;
  cancelConfirmationOpen.value = true;
}

function requestCancelFromModal() {
  if (!selectedOrder.value) return;
  const order = selectedOrder.value;
  closeViewModal();
  requestCancel(order);
}

async function cancelOrder() {
  const order = pendingCancellation.value;
  if (!order) return;
  cancelConfirmationOpen.value = false;
  cancellingId.value = order.id;
  error.value = '';

  try {
    const response = await fetch(`/api/v1/purchase-orders/${order.id}/cancel`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível cancelar o pedido de compra.');
    await loadOrders();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao cancelar pedido de compra.';
  } finally {
    cancellingId.value = null;
    pendingCancellation.value = null;
  }
}

onMounted(async () => {
  try {
    await Promise.all([loadOrders(), loadLookups()]);
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar os dados.';
  }
});

watch([search, branchFilter, supplierFilter, statusFilter], () => void loadOrders());
</script>

<template>
  <section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink
          to="/erp"
          class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        >
          ← Voltar ao ERP
        </RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Compras</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Pedidos de compra</h1>
        <p class="mt-2 text-slate-500">Gerencie pedidos de compra a fornecedores, cotações e status de recebimento.</p>
      </div>
      <RouterLink
        to="/erp/compras/novo"
        aria-label="Novo pedido de compra"
        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
      >
        Novo pedido de compra
      </RouterLink>
    </div>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <div class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label for="purchase-search" class="block text-sm font-medium text-slate-700">Buscar</label>
        <input
          id="purchase-search"
          v-model="search"
          type="search"
          placeholder="Número ou fornecedor…"
          class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        />
      </div>
      <div>
        <label for="purchase-branch-filter" class="block text-sm font-medium text-slate-700">Filial</label>
        <SearchableCombobox
          id="purchase-branch-filter"
          :model-value="branchFilter"
          :options="branchComboboxOptions"
          placeholder="Todas as filiais"
          @update:model-value="setBranchFilter"
        />
      </div>
      <div>
        <label for="purchase-supplier-filter" class="block text-sm font-medium text-slate-700">Fornecedor</label>
        <SearchableCombobox
          id="purchase-supplier-filter"
          :model-value="supplierFilter"
          :options="supplierComboboxOptions"
          placeholder="Todos os fornecedores"
          @update:model-value="setSupplierFilter"
        />
      </div>
      <div>
        <label for="purchase-status-filter" class="block text-sm font-medium text-slate-700">Status</label>
        <SearchableCombobox
          id="purchase-status-filter"
          :model-value="statusFilter"
          :options="statusComboboxOptions"
          placeholder="Todos os status"
          @update:model-value="setStatusFilter"
        />
      </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
      <DataGrid
        :columns="columns"
        :rows="rows"
        :loading="loading"
        empty-message="Nenhum pedido de compra encontrado."
      >
        <template #cell-status="{ value, row }">
          <span
            class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
            :class="statusBadgeClasses[String(row.status_raw)] ?? 'bg-slate-100 text-slate-700 border-slate-200'"
          >
            {{ value }}
          </span>
        </template>
        <template #actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
              :aria-label="`Visualizar pedido ${(row.order as PurchaseOrder).number}`"
              @click="openViewModal(row.order as PurchaseOrder)"
            >
              Visualizar
            </button>
            <RouterLink
              v-if="(row.order as PurchaseOrder).status !== 'received' && (row.order as PurchaseOrder).status !== 'cancelled'"
              :to="`/erp/compras/${(row.order as PurchaseOrder).id}/receber`"
              class="rounded-md border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 transition-colors hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
              :aria-label="`Receber pedido ${(row.order as PurchaseOrder).number}`"
            >
              Receber
            </RouterLink>
            <RouterLink
              v-if="(row.order as PurchaseOrder).status !== 'received' && (row.order as PurchaseOrder).status !== 'cancelled'"
              :to="`/erp/compras/${(row.order as PurchaseOrder).id}/editar`"
              class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
              :aria-label="`Editar pedido ${(row.order as PurchaseOrder).number}`"
            >
              Editar
            </RouterLink>
            <button
              v-if="(row.order as PurchaseOrder).status !== 'received' && (row.order as PurchaseOrder).status !== 'cancelled'"
              type="button"
              class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50"
              :aria-label="`Cancelar pedido ${(row.order as PurchaseOrder).number}`"
              :disabled="cancellingId === (row.order as PurchaseOrder).id"
              @click="requestCancel(row.order as PurchaseOrder)"
            >
              Cancelar
            </button>
          </div>
        </template>
      </DataGrid>
    </div>

    <!-- View Order Details Modal -->
    <Modal
      v-model="viewModalOpen"
      :title="`Detalhes do Pedido ${selectedOrder?.number ?? ''}`"
    >
      <div v-if="selectedOrder" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Filial</span>
            <p class="text-sm font-semibold text-slate-900">
              {{ selectedOrder.branch?.name ?? '—' }} ({{ selectedOrder.branch?.code ?? '—' }})
            </p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Fornecedor</span>
            <p class="text-sm font-semibold text-slate-900">
              {{ selectedOrder.supplier?.name ?? '—' }}
              <span v-if="selectedOrder.supplier?.document" class="font-normal text-slate-500">({{ selectedOrder.supplier.document }})</span>
            </p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Data do Pedido</span>
            <p class="text-sm text-slate-900">{{ formatDate(selectedOrder.order_date) }}</p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Previsão de Entrega</span>
            <p class="text-sm text-slate-900">{{ formatDate(selectedOrder.expected_delivery_date) }}</p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Status</span>
            <span
              class="mt-1 inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
              :class="statusBadgeClasses[selectedOrder.status] ?? 'bg-slate-100 text-slate-700'"
            >
              {{ statusLabels[selectedOrder.status] ?? selectedOrder.status }}
            </span>
          </div>
        </div>

        <div v-if="selectedOrder.notes" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700 border border-slate-200">
          <strong class="block text-xs font-medium text-slate-500 uppercase mb-1">Observações</strong>
          {{ selectedOrder.notes }}
        </div>

        <div>
          <h3 class="text-sm font-semibold text-slate-900 mb-2">Itens do Pedido</h3>
          <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full text-left text-xs">
              <thead class="bg-slate-50 uppercase text-slate-500">
                <tr>
                  <th class="px-3 py-2 font-semibold">Produto</th>
                  <th class="px-3 py-2 font-semibold">SKU</th>
                  <th class="px-3 py-2 text-right font-semibold">Qtd</th>
                  <th class="px-3 py-2 text-right font-semibold">Custo Unit.</th>
                  <th class="px-3 py-2 text-right font-semibold">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="item in selectedOrder.items" :key="item.id">
                  <td class="px-3 py-2 font-medium text-slate-900">
                    {{ item.product?.name ?? 'Produto não identificado' }}
                    <span v-if="item.notes" class="block text-[11px] text-slate-400">{{ item.notes }}</span>
                  </td>
                  <td class="px-3 py-2 text-slate-500">{{ item.product?.sku ?? '—' }}</td>
                  <td class="px-3 py-2 text-right text-slate-900">{{ item.quantity }}</td>
                  <td class="px-3 py-2 text-right text-slate-900">{{ formatPrice(item.unit_cost) }}</td>
                  <td class="px-3 py-2 text-right font-semibold text-slate-900">{{ formatPrice(item.total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-1 text-sm">
          <div class="flex justify-between text-slate-600">
            <span>Subtotal:</span>
            <span>{{ formatPrice(selectedOrder.subtotal) }}</span>
          </div>
          <div v-if="Number(selectedOrder.shipping_cost) > 0" class="flex justify-between text-slate-600">
            <span>Frete / Custos adicionais:</span>
            <span>+ {{ formatPrice(selectedOrder.shipping_cost) }}</span>
          </div>
          <div v-if="Number(selectedOrder.discount) > 0" class="flex justify-between text-slate-600">
            <span>Desconto:</span>
            <span class="text-red-600">- {{ formatPrice(selectedOrder.discount) }}</span>
          </div>
          <div class="flex justify-between pt-2 border-t border-slate-200 font-bold text-base text-slate-900">
            <span>Total Geral:</span>
            <span>{{ formatPrice(selectedOrder.total) }}</span>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <button
            type="button"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="closeViewModal"
          >
            Fechar
          </button>
          <button
            v-if="selectedOrder.status !== 'received' && selectedOrder.status !== 'cancelled'"
            type="button"
            class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
            @click="requestCancelFromModal"
          >
            Cancelar pedido
          </button>
          <RouterLink
            v-if="selectedOrder.status !== 'received' && selectedOrder.status !== 'cancelled'"
            :to="`/erp/compras/${selectedOrder.id}/receber`"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >
            Receber mercadorias
          </RouterLink>
          <RouterLink
            v-if="selectedOrder.status !== 'received' && selectedOrder.status !== 'cancelled'"
            :to="`/erp/compras/${selectedOrder.id}/editar`"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
          >
            Editar pedido
          </RouterLink>
        </div>
      </div>
    </Modal>

    <!-- Cancel Confirmation Dialog -->
    <ConfirmationDialog
      v-model="cancelConfirmationOpen"
      title="Cancelar pedido de compra"
      :message="`Tem certeza de que deseja cancelar o pedido de compra ${pendingCancellation?.number ?? ''}? Esta ação não poderá ser desfeita.`"
      confirm-label="Cancelar pedido"
      cancel-label="Voltar"
      :busy="cancellingId !== null"
      @confirm="cancelOrder"
    />
  </section>
</template>
