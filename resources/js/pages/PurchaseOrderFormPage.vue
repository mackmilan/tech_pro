<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

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
  sale_price: string | number;
}

interface FormItem {
  id: string; // client-side key
  product_id: string;
  quantity: number;
  unit_cost: number;
  notes: string;
}

interface PurchaseOrderResponse {
  id: string;
  branch_id: string;
  supplier_id: string;
  number: string;
  status: 'draft' | 'ordered' | 'approved' | 'received' | 'cancelled';
  order_date: string;
  expected_delivery_date: string | null;
  shipping_cost: string | number;
  discount: string | number;
  notes: string | null;
  items: Array<{
    id: string;
    product_id: string;
    quantity: number;
    unit_cost: string | number;
    notes: string | null;
  }>;
}

const route = useRoute();
const router = useRouter();

const orderId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null));
const editing = computed(() => orderId.value !== null);

const branches = ref<Branch[]>([]);
const suppliers = ref<Supplier[]>([]);
const products = ref<Product[]>([]);

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');

const today = new Date().toISOString().slice(0, 10);

const form = ref({
  branch_id: '',
  supplier_id: '',
  number: '',
  status: 'draft',
  order_date: today,
  expected_delivery_date: '',
  shipping_cost: 0,
  discount: 0,
  notes: '',
  items: [
    {
      id: 'item-1',
      product_id: '',
      quantity: 1,
      unit_cost: 0,
      notes: '',
    },
  ] as FormItem[],
});

const branchOptions = computed<ComboboxOption[]>(() =>
  branches.value.map((b) => ({ value: b.id, label: `${b.name} (${b.code})` }))
);

const supplierOptions = computed<ComboboxOption[]>(() =>
  suppliers.value.map((s) => ({ value: s.id, label: s.name }))
);

const statusOptions: ComboboxOption[] = [
  { value: 'draft', label: 'Rascunho' },
  { value: 'ordered', label: 'Emitido' },
  { value: 'approved', label: 'Aprovado' },
  { value: 'received', label: 'Recebido' },
  { value: 'cancelled', label: 'Cancelado' },
];

const productOptions = computed<ComboboxOption[]>(() =>
  products.value.map((p) => ({
    value: p.id,
    label: `${p.name} (SKU: ${p.sku})`,
  }))
);

function formatPrice(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

const subtotal = computed(() =>
  form.value.items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_cost) || 0), 0)
);

const total = computed(() => {
  const calculated = subtotal.value + Number(form.value.shipping_cost || 0) - Number(form.value.discount || 0);
  return Math.max(0, calculated);
});

function addItem() {
  form.value.items.push({
    id: `item-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    product_id: '',
    quantity: 1,
    unit_cost: 0,
    notes: '',
  });
}

function removeItem(index: number) {
  if (form.value.items.length > 1) {
    form.value.items.splice(index, 1);
  }
}

function setBranch(value: string | string[] | null) {
  form.value.branch_id = typeof value === 'string' ? value : '';
}

function setSupplier(value: string | string[] | null) {
  form.value.supplier_id = typeof value === 'string' ? value : '';
}

function setStatus(value: string | string[] | null) {
  form.value.status = typeof value === 'string' ? value : 'draft';
}

function setItemProduct(item: FormItem, value: string | string[] | null) {
  item.product_id = typeof value === 'string' ? value : '';
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
  const [branchesRes, suppliersRes, productsRes] = await Promise.all([
    fetch('/api/v1/branches?per_page=100', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/suppliers?per_page=100', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/products?per_page=100&active=1', { headers: { Accept: 'application/json' } }),
  ]);

  if (!branchesRes.ok) throw await responseError(branchesRes, 'Não foi possível carregar as filiais.');
  if (!suppliersRes.ok) throw await responseError(suppliersRes, 'Não foi possível carregar os fornecedores.');
  if (!productsRes.ok) throw await responseError(productsRes, 'Não foi possível carregar os produtos.');

  branches.value = (await branchesRes.json() as { data: Branch[] }).data;
  suppliers.value = (await suppliersRes.json() as { data: Supplier[] }).data;
  products.value = (await productsRes.json() as { data: Product[] }).data;
}

async function loadOrder(id: string) {
  const response = await fetch(`/api/v1/purchase-orders/${id}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw await responseError(response, 'Não foi possível carregar o pedido de compra.');
  const order = await response.json() as PurchaseOrderResponse;

  form.value = {
    branch_id: order.branch_id,
    supplier_id: order.supplier_id,
    number: order.number,
    status: order.status,
    order_date: order.order_date,
    expected_delivery_date: order.expected_delivery_date ?? '',
    shipping_cost: Number(order.shipping_cost) || 0,
    discount: Number(order.discount) || 0,
    notes: order.notes ?? '',
    items: (order.items && order.items.length > 0)
      ? order.items.map((item, index) => ({
          id: `item-${item.id ?? index}`,
          product_id: item.product_id,
          quantity: item.quantity,
          unit_cost: Number(item.unit_cost) || 0,
          notes: item.notes ?? '',
        }))
      : [
          {
            id: 'item-1',
            product_id: '',
            quantity: 1,
            unit_cost: 0,
            notes: '',
          },
        ],
  };
}

async function saveOrder() {
  formError.value = '';

  if (!form.value.branch_id) {
    formError.value = 'Selecione a filial para o pedido.';
    return;
  }

  if (!form.value.supplier_id) {
    formError.value = 'Selecione o fornecedor para o pedido.';
    return;
  }

  if (!form.value.order_date) {
    formError.value = 'Informe a data do pedido.';
    return;
  }

  if (form.value.items.length === 0) {
    formError.value = 'Adicione ao menos um item ao pedido.';
    return;
  }

  for (let i = 0; i < form.value.items.length; i++) {
    const item = form.value.items[i];
    if (!item.product_id) {
      formError.value = `Selecione o produto na linha ${i + 1}.`;
      return;
    }
    if (!item.quantity || item.quantity < 1) {
      formError.value = `A quantidade na linha ${i + 1} deve ser pelo menos 1.`;
      return;
    }
    if (item.unit_cost < 0) {
      formError.value = `O custo unitário na linha ${i + 1} não pode ser negativo.`;
      return;
    }
  }

  if (Number(form.value.discount) > subtotal.value + Number(form.value.shipping_cost || 0)) {
    formError.value = 'O desconto não pode superar o valor total do pedido.';
    return;
  }

  saving.value = true;
  const payload = {
    branch_id: form.value.branch_id,
    supplier_id: form.value.supplier_id,
    number: form.value.number.trim() || undefined,
    status: form.value.status,
    order_date: form.value.order_date,
    expected_delivery_date: form.value.expected_delivery_date || null,
    shipping_cost: Number(form.value.shipping_cost) || 0,
    discount: Number(form.value.discount) || 0,
    notes: form.value.notes.trim() || null,
    items: form.value.items.map((item) => ({
      product_id: item.product_id,
      quantity: Number(item.quantity),
      unit_cost: Number(item.unit_cost),
      notes: item.notes.trim() || null,
    })),
  };

  try {
    const url = editing.value ? `/api/v1/purchase-orders/${orderId.value}` : '/api/v1/purchase-orders';
    const method = editing.value ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o pedido de compra.');
    await router.push('/erp/compras');
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar o pedido.';
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  error.value = '';
  try {
    await loadLookups();
    if (orderId.value) {
      await loadOrder(orderId.value);
    }
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao carregar dados do formulário.';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="w-full">
    <div class="mb-8">
      <RouterLink
        to="/erp/compras"
        class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
      >
        ← Voltar aos pedidos de compra
      </RouterLink>
      <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Compras</p>
      <h1 class="mt-1 text-3xl font-bold tracking-tight">
        {{ editing ? 'Editar pedido de compra' : 'Novo pedido de compra' }}
      </h1>
      <p class="mt-2 text-slate-500">
        Preencha os dados do pedido de compra, fornecedor e itens para reposição ou aquisição de estoque.
      </p>
    </div>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <div v-if="formError" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ formError }}
    </div>

    <div v-if="loading" role="status" class="py-12 text-center text-sm text-slate-500">
      Carregando informações…
    </div>

    <form v-else aria-label="Formulário de pedido de compra" class="space-y-8" @submit.prevent="saveOrder">
      <!-- Section 1: Dados Principais -->
      <fieldset :disabled="saving" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 space-y-6">
        <legend class="text-lg font-semibold text-slate-900">1. Informações Gerais</legend>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label for="po-branch" class="block text-sm font-medium text-slate-700">Filial de destino *</label>
            <SearchableCombobox
              id="po-branch"
              :model-value="form.branch_id"
              :options="branchOptions"
              placeholder="Selecione a filial…"
              required
              @update:model-value="setBranch"
            />
          </div>

          <div>
            <label for="po-supplier" class="block text-sm font-medium text-slate-700">Fornecedor *</label>
            <SearchableCombobox
              id="po-supplier"
              :model-value="form.supplier_id"
              :options="supplierOptions"
              placeholder="Selecione o fornecedor…"
              required
              @update:model-value="setSupplier"
            />
          </div>

          <div>
            <label for="po-number" class="block text-sm font-medium text-slate-700">Número do pedido</label>
            <input
              id="po-number"
              v-model="form.number"
              type="text"
              placeholder="Automático (ex: PC-20261106-0001)"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="po-status" class="block text-sm font-medium text-slate-700">Status *</label>
            <SearchableCombobox
              id="po-status"
              :model-value="form.status"
              :options="statusOptions"
              placeholder="Selecione o status…"
              required
              @update:model-value="setStatus"
            />
          </div>

          <div>
            <label for="po-order-date" class="block text-sm font-medium text-slate-700">Data do pedido *</label>
            <input
              id="po-order-date"
              v-model="form.order_date"
              type="date"
              required
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label for="po-delivery-date" class="block text-sm font-medium text-slate-700">Previsão de entrega</label>
            <input
              id="po-delivery-date"
              v-model="form.expected_delivery_date"
              type="date"
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>
      </fieldset>

      <!-- Section 2: Itens do Pedido -->
      <fieldset :disabled="saving" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <legend class="text-lg font-semibold text-slate-900">2. Itens do Pedido</legend>
          <button
            type="button"
            class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="addItem"
          >
            + Adicionar produto
          </button>
        </div>

        <div class="space-y-4">
          <div
            v-for="(item, index) in form.items"
            :key="item.id"
            class="relative rounded-lg border border-slate-200 p-4 transition-colors bg-slate-50/50"
          >
            <div class="grid gap-4 sm:grid-cols-12 items-end">
              <div class="sm:col-span-5">
                <label :for="`item-product-${index}`" class="block text-xs font-medium text-slate-700">
                  Produto {{ index + 1 }} *
                </label>
                <SearchableCombobox
                  :id="`item-product-${index}`"
                  :model-value="item.product_id"
                  :options="productOptions"
                  placeholder="Selecione o produto…"
                  required
                  @update:model-value="(val) => setItemProduct(item, val)"
                />
              </div>

              <div class="sm:col-span-2">
                <label :for="`item-qty-${index}`" class="block text-xs font-medium text-slate-700">
                  Quantidade *
                </label>
                <input
                  :id="`item-qty-${index}`"
                  v-model.number="item.quantity"
                  type="number"
                  min="1"
                  required
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-2">
                <label :for="`item-cost-${index}`" class="block text-xs font-medium text-slate-700">
                  Custo Unit. (R$) *
                </label>
                <input
                  :id="`item-cost-${index}`"
                  v-model.number="item.unit_cost"
                  type="number"
                  step="0.01"
                  min="0"
                  required
                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div class="sm:col-span-2">
                <span class="block text-xs font-medium text-slate-500">Total da linha</span>
                <p class="mt-2 text-sm font-semibold text-slate-900">
                  {{ formatPrice((Number(item.quantity) || 0) * (Number(item.unit_cost) || 0)) }}
                </p>
              </div>

              <div class="sm:col-span-1 flex justify-end">
                <button
                  type="button"
                  aria-label="Remover item"
                  :disabled="form.items.length <= 1"
                  class="rounded p-2 text-slate-400 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-30 disabled:hover:text-slate-400"
                  @click="removeItem(index)"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>

            <div class="mt-3">
              <input
                :id="`item-notes-${index}`"
                v-model="item.notes"
                type="text"
                placeholder="Observação do item (opcional, ex: cor, lote, etc.)"
                class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              />
            </div>
          </div>
        </div>
      </fieldset>

      <!-- Section 3: Totais e Observações -->
      <fieldset :disabled="saving" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 space-y-6">
        <legend class="text-lg font-semibold text-slate-900">3. Fechamento Financeiro e Observações</legend>

        <div class="grid gap-6 lg:grid-cols-2">
          <div class="space-y-4">
            <div>
              <label for="po-notes" class="block text-sm font-medium text-slate-700">Observações gerais do pedido</label>
              <textarea
                id="po-notes"
                v-model="form.notes"
                rows="4"
                placeholder="Informações de faturamento, prazos acordados, instruções para entrega…"
                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              ></textarea>
            </div>
          </div>

          <div class="rounded-xl border border-slate-200 bg-slate-50 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-slate-900">Resumo de Valores</h3>

            <div class="flex justify-between text-sm text-slate-600">
              <span>Subtotal dos itens:</span>
              <span class="font-medium text-slate-900">{{ formatPrice(subtotal) }}</span>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label for="po-shipping" class="block text-xs font-medium text-slate-700">Frete / Custos (R$)</label>
                <input
                  id="po-shipping"
                  v-model.number="form.shipping_cost"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label for="po-discount" class="block text-xs font-medium text-slate-700">Desconto (R$)</label>
                <input
                  id="po-discount"
                  v-model.number="form.discount"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>
            </div>

            <div class="pt-4 border-t border-slate-200 flex justify-between items-baseline">
              <span class="text-base font-bold text-slate-900">Total do Pedido:</span>
              <span class="text-2xl font-bold text-indigo-700">{{ formatPrice(total) }}</span>
            </div>
          </div>
        </div>
      </fieldset>

      <!-- Form Actions -->
      <div class="flex items-center justify-end gap-3 pt-4">
        <RouterLink
          to="/erp/compras"
          class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        >
          Cancelar
        </RouterLink>
        <button
          type="submit"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
        >
          {{ saving ? 'Salvando…' : (editing ? 'Atualizar pedido' : 'Salvar pedido de compra') }}
        </button>
      </div>
    </form>
  </section>
</template>

