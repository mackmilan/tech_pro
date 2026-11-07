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

interface Customer {
  id: string;
  name: string;
  document: string | null;
  phone: string | null;
  email: string | null;
}

interface Technician {
  id: string;
  name: string;
  specialty?: string | null;
}

interface ChecklistImage {
  id: string;
  imageable_id: string;
  imageable_type: string;
  url: string | null;
  original_name: string | null;
  mime_type: string | null;
  size: number | null;
  is_primary: boolean;
  sort_order: number;
}

interface ServiceOrderChecklist {
  id?: string;
  service_checklist_id?: string | null;
  item_name: string;
  category?: string | null;
  stage: 'entry' | 'exit';
  status: 'ok' | 'defect' | 'not_tested' | 'not_applicable';
  notes?: string | null;
  verified_at?: string | null;
  images?: ChecklistImage[];
  uploading?: boolean;
}

interface StatusRule {
  status: string;
  allow_view: boolean;
  allow_edit: boolean;
  allow_execute: boolean;
  allow_cancel: boolean;
  allow_print: boolean;
  allowed_transitions?: string[] | null;
}

interface ServiceOrderItem {
  id: string;
  type: 'service' | 'product';
  description: string;
  quantity: number;
  unit_price: string | number;
  discount: string | number;
  total: string | number;
  notes: string | null;
}

interface ServiceOrder {
  id: string;
  branch_id: string;
  customer_id: string;
  technician_id: string | null;
  number: string;
  status: 'open' | 'in_analysis' | 'approved' | 'in_progress' | 'waiting_parts' | 'completed' | 'delivered' | 'cancelled';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  device_brand: string | null;
  device_model: string;
  device_imei_serial: string | null;
  device_color: string | null;
  device_password: string | null;
  device_condition: string | null;
  accessories: string | null;
  defect_reported: string;
  defect_diagnosed: string | null;
  solution_applied: string | null;
  technical_notes: string | null;
  opened_at: string;
  expected_at: string | null;
  completed_at: string | null;
  delivered_at: string | null;
  services_amount: string | number;
  products_amount: string | number;
  discount: string | number;
  total: string | number;
  notes: string | null;
  warranty_terms: string | null;
  branch?: Branch | null;
  customer?: Customer | null;
  technician?: Technician | null;
  items?: ServiceOrderItem[];
  checklists?: ServiceOrderChecklist[];
}

const orders = ref<ServiceOrder[]>([]);
const branches = ref<Branch[]>([]);
const loading = ref(false);
const cancellingId = ref<string | null>(null);
const error = ref('');
const cancelConfirmationOpen = ref(false);
const pendingCancellation = ref<ServiceOrder | null>(null);
const selectedOrder = ref<ServiceOrder | null>(null);
const viewModalOpen = ref(false);

const executeModalOpen = ref(false);
const executingOrder = ref<ServiceOrder | null>(null);
const executingSaving = ref(false);
const executeError = ref('');
const technicians = ref<Technician[]>([]);

const executeStatusOptions: ComboboxOption[] = [
  { value: 'in_progress', label: 'Em andamento (Executando reparo)' },
  { value: 'waiting_parts', label: 'Aguardando peças' },
  { value: 'completed', label: 'Concluída (Reparo finalizado)' },
  { value: 'delivered', label: 'Entregue ao cliente' },
  { value: 'cancelled', label: 'Cancelada' },
];

const statusRules = ref<Record<string, StatusRule>>({});

function canPerformAction(status: string, action: 'view' | 'edit' | 'execute' | 'cancel' | 'print'): boolean {
  const rule = statusRules.value[status];
  if (rule) {
    if (action === 'view') return rule.allow_view ?? true;
    if (action === 'edit') return rule.allow_edit ?? false;
    if (action === 'execute') return rule.allow_execute ?? false;
    if (action === 'cancel') return rule.allow_cancel ?? false;
    if (action === 'print') return rule.allow_print ?? true;
  }

  // Fallback defaults if no rule is stored yet or before rules loaded:
  if (action === 'view' || action === 'print') return true;
  return status !== 'cancelled' && status !== 'delivered';
}

async function loadStatusRules() {
  try {
    const response = await fetch('/api/v1/service-order-status-rules?all=1&active=1', {
      headers: { Accept: 'application/json' },
    });
    if (response.ok) {
      const payload = await response.json() as { data: StatusRule[] };
      const map: Record<string, StatusRule> = {};
      for (const rule of payload.data) {
        map[rule.status] = rule;
      }
      statusRules.value = map;
    }
  } catch {
    // Non-blocking lookup, safe fallback to default visibility rules
  }
}

const availableExecuteStatusOptions = computed<ComboboxOption[]>(() => {
  if (!executingOrder.value) return executeStatusOptions;
  const currentStatus = executingOrder.value.status;
  const transitions = statusRules.value[currentStatus]?.allowed_transitions;
  if (!transitions || transitions.length === 0) {
    return executeStatusOptions;
  }
  const allowed = new Set([...transitions, currentStatus]);
  const filtered = executeStatusOptions.filter((opt) => allowed.has(opt.value));
  return filtered.length > 0 ? filtered : executeStatusOptions;
});

const technicianOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Nenhum técnico atribuído' },
  ...technicians.value.map((t) => ({
    value: t.id,
    label: t.specialty ? `${t.name} (${t.specialty})` : t.name,
  })),
]);

const executeForm = ref({
  technician_id: '',
  status: 'in_progress',
  defect_diagnosed: '',
  solution_applied: '',
  technical_notes: '',
  checklists: [] as ServiceOrderChecklist[],
});

function openExecuteModal(order: ServiceOrder) {
  executingOrder.value = order;
  executeError.value = '';

  let initialStatus = order.status;
  if (['open', 'in_analysis', 'approved'].includes(order.status)) {
    initialStatus = 'in_progress';
  }

  executeForm.value = {
    technician_id: order.technician_id ?? '',
    status: initialStatus,
    defect_diagnosed: order.defect_diagnosed ?? '',
    solution_applied: order.solution_applied ?? '',
    technical_notes: order.technical_notes ?? '',
    checklists: (order.checklists || []).map((c) => ({
      id: c.id,
      service_checklist_id: c.service_checklist_id,
      item_name: c.item_name,
      category: c.category,
      stage: c.stage,
      status: c.status,
      notes: c.notes ?? '',
      verified_at: c.verified_at,
      images: c.images ? [...c.images] : [],
      uploading: false,
    })),
  };

  executeModalOpen.value = true;
  if (viewModalOpen.value) {
    viewModalOpen.value = false;
  }
}

function closeExecuteModal() {
  executeModalOpen.value = false;
  executingOrder.value = null;
  executeError.value = '';
}

function setChecklistStatus(item: ServiceOrderChecklist, status: 'ok' | 'defect' | 'not_tested' | 'not_applicable') {
  item.status = status;
}

const previewingImage = ref<{ url: string; name?: string } | null>(null);
const photoModalOpen = computed({
  get: () => previewingImage.value !== null,
  set: (val: boolean) => {
    if (!val) previewingImage.value = null;
  },
});
const executeImageError = ref<Record<string, string>>({});

function openPhotoPreview(url: string, name?: string) {
  previewingImage.value = { url, name };
}

async function handleExecuteChecklistPhotoSelect(chk: ServiceOrderChecklist, event: Event) {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  if (files.length === 0 || !chk.id) return;

  delete executeImageError.value[chk.id];
  chk.uploading = true;

  try {
    for (const file of files) {
      const formData = new FormData();
      formData.append('image', file);
      const res = await fetch(`/api/v1/service-order-checklists/${chk.id}/images`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
        body: formData,
      });
      if (!res.ok) throw await responseError(res, 'Erro ao enviar foto.');
      const savedImg = await res.json() as ChecklistImage;
      if (!chk.images) chk.images = [];
      chk.images.push(savedImg);
    }
  } catch (ex) {
    executeImageError.value[chk.id] = ex instanceof Error ? ex.message : 'Erro ao enviar foto.';
  } finally {
    chk.uploading = false;
  }

  target.value = '';
}

async function removeExecuteChecklistImage(chk: ServiceOrderChecklist, img: ChecklistImage) {
  if (!chk.id) return;
  delete executeImageError.value[chk.id];
  try {
    const res = await fetch(`/api/v1/images/${img.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!res.ok) throw await responseError(res, 'Não foi possível excluir a foto.');
    if (chk.images) {
      chk.images = chk.images.filter((i) => i.id !== img.id);
    }
  } catch (ex) {
    executeImageError.value[chk.id] = ex instanceof Error ? ex.message : 'Erro ao excluir foto.';
  }
}

async function saveExecution() {
  if (!executingOrder.value) return;

  executingSaving.value = true;
  executeError.value = '';

  try {
    const payload = {
      technician_id: executeForm.value.technician_id || null,
      status: executeForm.value.status,
      defect_diagnosed: executeForm.value.defect_diagnosed.trim() || null,
      solution_applied: executeForm.value.solution_applied.trim() || null,
      technical_notes: executeForm.value.technical_notes.trim() || null,
      checklists: executeForm.value.checklists.map((c) => ({
        id: c.id,
        service_checklist_id: c.service_checklist_id || null,
        item_name: c.item_name,
        category: c.category || null,
        stage: c.stage,
        status: c.status,
        notes: c.notes ? c.notes.trim() : null,
      })),
    };

    const response = await fetch(`/api/v1/service-orders/${executingOrder.value.id}/execute`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a execução da ordem de serviço.');

    const updated = (await response.json()) as ServiceOrder;

    const index = orders.value.findIndex((o) => o.id === updated.id);
    if (index !== -1) {
      orders.value[index] = updated;
    }

    if (selectedOrder.value?.id === updated.id) {
      selectedOrder.value = updated;
    }

    closeExecuteModal();
  } catch (exception) {
    executeError.value = exception instanceof Error ? exception.message : 'Erro ao salvar execução.';
  } finally {
    executingSaving.value = false;
  }
}

async function loadTechnicians() {
  try {
    const response = await fetch('/api/v1/technicians?per_page=100&active=1', { headers: { Accept: 'application/json' } });
    if (response.ok) {
      const payload = (await response.json()) as { data: Technician[] };
      technicians.value = payload.data;
    }
  } catch {
    // Non-blocking lookup
  }
}

const search = ref('');
const branchFilter = ref('');
const statusFilter = ref('todos');
const priorityFilter = ref('todos');

const columns: DataGridColumn[] = [
  { key: 'number', label: 'Número' },
  { key: 'customer', label: 'Cliente' },
  { key: 'device', label: 'Aparelho' },
  { key: 'status', label: 'Status' },
  { key: 'priority', label: 'Prioridade' },
  { key: 'opened_at', label: 'Abertura' },
  { key: 'total', label: 'Total' },
];

const statusLabels: Record<string, string> = {
  open: 'Aberta',
  in_analysis: 'Em análise',
  approved: 'Aprovada',
  in_progress: 'Em andamento',
  waiting_parts: 'Aguardando peças',
  completed: 'Concluída',
  delivered: 'Entregue',
  cancelled: 'Cancelada',
};

const statusClasses: Record<string, string> = {
  open: 'bg-slate-100 text-slate-700 border-slate-200',
  in_analysis: 'bg-amber-50 text-amber-800 border-amber-200',
  approved: 'bg-blue-50 text-blue-700 border-blue-200',
  in_progress: 'bg-indigo-50 text-indigo-700 border-indigo-200',
  waiting_parts: 'bg-orange-50 text-orange-800 border-orange-200',
  completed: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  delivered: 'bg-green-100 text-green-900 border-green-300',
  cancelled: 'bg-red-50 text-red-700 border-red-200',
};

const priorityLabels: Record<string, string> = {
  low: 'Baixa',
  normal: 'Normal',
  high: 'Alta',
  urgent: 'Urgente',
};

const priorityClasses: Record<string, string> = {
  low: 'text-slate-600',
  normal: 'text-slate-800',
  high: 'text-amber-700 font-semibold',
  urgent: 'text-red-700 font-bold',
};

const branchFilterOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Todas as filiais' },
  ...branches.value.map((b) => ({ value: b.id, label: `${b.name} (${b.code})` })),
]);

const statusFilterOptions: ComboboxOption[] = [
  { value: 'todos', label: 'Todos os status' },
  { value: 'open', label: 'Aberta' },
  { value: 'in_analysis', label: 'Em análise' },
  { value: 'approved', label: 'Aprovada' },
  { value: 'in_progress', label: 'Em andamento' },
  { value: 'waiting_parts', label: 'Aguardando peças' },
  { value: 'completed', label: 'Concluída' },
  { value: 'delivered', label: 'Entregue' },
  { value: 'cancelled', label: 'Cancelada' },
];

const priorityFilterOptions: ComboboxOption[] = [
  { value: 'todos', label: 'Todas as prioridades' },
  { value: 'low', label: 'Baixa' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

function formatDate(dateString: string | null): string {
  if (!dateString) return '—';
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    });
  } catch {
    return dateString;
  }
}

function formatCurrency(amount: string | number | null | undefined): string {
  const num = Number(amount) || 0;
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(num);
}

const rows = computed(() =>
  orders.value.map((order) => {
    const deviceName = order.device_brand ? `${order.device_brand} ${order.device_model}` : order.device_model;
    return {
      id: order.id,
      number: order.number,
      customer: order.customer?.name ?? 'Cliente não informado',
      device: deviceName,
      status: order.status,
      priority: order.priority,
      opened_at: formatDate(order.opened_at),
      total: formatCurrency(order.total),
      order,
    };
  })
);

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadBranches() {
  try {
    const response = await fetch('/api/v1/branches?per_page=100', { headers: { Accept: 'application/json' } });
    if (response.ok) {
      const payload = await response.json() as { data: Branch[] };
      branches.value = payload.data;
    }
  } catch {
    // Non-blocking lookup
  }
}

async function loadOrders() {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams();
    if (search.value.trim()) params.append('search', search.value.trim());
    if (branchFilter.value) params.append('branch_id', branchFilter.value);
    if (statusFilter.value && statusFilter.value !== 'todos') params.append('status', statusFilter.value);
    if (priorityFilter.value && priorityFilter.value !== 'todos') params.append('priority', priorityFilter.value);
    params.append('per_page', '50');

    const url = `/api/v1/service-orders?${params.toString()}`;
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar as ordens de serviço.');
    const payload = await response.json() as { data: ServiceOrder[] };
    orders.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar as ordens de serviço.';
  } finally {
    loading.value = false;
  }
}

let searchDebounceTimeout: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
  if (searchDebounceTimeout) clearTimeout(searchDebounceTimeout);
  searchDebounceTimeout = setTimeout(() => {
    loadOrders();
  }, 300);
});

watch([branchFilter, statusFilter, priorityFilter], () => {
  loadOrders();
});

function openDetails(order: ServiceOrder) {
  selectedOrder.value = order;
  viewModalOpen.value = true;
}

function promptCancel(order: ServiceOrder) {
  pendingCancellation.value = order;
  cancelConfirmationOpen.value = true;
}

function promptCancelFromModal() {
  if (!selectedOrder.value) return;
  const order = selectedOrder.value;
  viewModalOpen.value = false;
  promptCancel(order);
}

function openExecuteFromModal() {
  if (!selectedOrder.value) return;
  const order = selectedOrder.value;
  viewModalOpen.value = false;
  openExecuteModal(order);
}

async function confirmCancel() {
  if (!pendingCancellation.value) return;
  const id = pendingCancellation.value.id;
  cancellingId.value = id;
  try {
    const response = await fetch(`/api/v1/service-orders/${id}/cancel`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível cancelar a ordem de serviço.');
    cancelConfirmationOpen.value = false;
    pendingCancellation.value = null;
    await loadOrders();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao cancelar ordem de serviço.';
  } finally {
    cancellingId.value = null;
  }
}

onMounted(async () => {
  await Promise.all([loadBranches(), loadTechnicians(), loadStatusRules(), loadOrders()]);
});
</script>

<template>
  <section class="w-full">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink to="/crm" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">CRM</RouterLink>
          <span aria-hidden="true">/</span>
          <span class="font-medium text-slate-900">Ordens de serviço</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Ordens de Serviço</h1>
        <p class="mt-1 text-sm text-slate-500">Gerencie reparos, orçamentos, diagnósticos e manutenções técnicas de clientes.</p>
      </div>
      <div>
        <RouterLink
          to="/crm/ordens-de-servico/nova"
          class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        >
          <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Nova ordem de serviço
        </RouterLink>
      </div>
    </header>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Filters Section -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label for="filter-search" class="block text-sm font-medium text-slate-700">Buscar</label>
          <input
            id="filter-search"
            v-model="search"
            type="text"
            placeholder="Número, cliente, aparelho, IMEI..."
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label for="filter-branch" class="block text-sm font-medium text-slate-700">Filial</label>
          <SearchableCombobox
            id="filter-branch"
            v-model="branchFilter"
            :options="branchFilterOptions"
            placeholder="Filtrar filial..."
            class="mt-1"
          />
        </div>

        <div>
          <label for="filter-status" class="block text-sm font-medium text-slate-700">Status</label>
          <SearchableCombobox
            id="filter-status"
            v-model="statusFilter"
            :options="statusFilterOptions"
            placeholder="Filtrar status..."
            class="mt-1"
          />
        </div>

        <div>
          <label for="filter-priority" class="block text-sm font-medium text-slate-700">Prioridade</label>
          <SearchableCombobox
            id="filter-priority"
            v-model="priorityFilter"
            :options="priorityFilterOptions"
            placeholder="Filtrar prioridade..."
            class="mt-1"
          />
        </div>
      </div>
    </div>

    <!-- Grid / Table -->
    <DataGrid
      :columns="columns"
      :rows="rows"
      :loading="loading"
      empty-message="Nenhuma ordem de serviço encontrada."
    >
      <template #cell-status="{ row }">
        <span
          class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-medium"
          :class="statusClasses[row.status as string] || 'bg-slate-100 text-slate-800 border-slate-200'"
        >
          {{ statusLabels[row.status as string] || row.status }}
        </span>
      </template>

      <template #cell-priority="{ row }">
        <span :class="priorityClasses[row.priority as string] || 'text-slate-700'">
          {{ priorityLabels[row.priority as string] || row.priority }}
        </span>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <button
            v-if="canPerformAction((row.order as ServiceOrder).status, 'view')"
            type="button"
            title="Visualizar detalhes"
            aria-label="Visualizar detalhes"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-slate-100 hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="openDetails(row.order as ServiceOrder)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
          </button>

          <button
            v-if="canPerformAction((row.order as ServiceOrder).status, 'execute')"
            type="button"
            title="Executar ordem de serviço"
            aria-label="Executar ordem de serviço"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-indigo-50 hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="openExecuteModal(row.order as ServiceOrder)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
            </svg>
          </button>

          <RouterLink
            v-if="canPerformAction((row.order as ServiceOrder).status, 'edit')"
            :to="`/crm/ordens-de-servico/${row.id}/editar`"
            title="Editar ordem de serviço"
            aria-label="Editar ordem de serviço"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-slate-100 hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </RouterLink>

          <button
            v-if="canPerformAction((row.order as ServiceOrder).status, 'cancel')"
            type="button"
            title="Cancelar ordem de serviço"
            aria-label="Cancelar ordem de serviço"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-50"
            :disabled="cancellingId === (row.order as ServiceOrder).id"
            @click="promptCancel(row.order as ServiceOrder)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <!-- Modal de Visualização da OS -->
    <Modal
      v-model="viewModalOpen"
      :title="`Ordem de Serviço ${selectedOrder?.number ?? ''}`"
      max-width-class="max-w-4xl"
    >
      <div v-if="selectedOrder" class="space-y-6 text-sm text-slate-800">
        <!-- Status & Meta Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500">Status:</span>
            <span
              class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-medium"
              :class="statusClasses[selectedOrder.status] || 'bg-slate-100 text-slate-800 border-slate-200'"
            >
              {{ statusLabels[selectedOrder.status] || selectedOrder.status }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500">Prioridade:</span>
            <span :class="priorityClasses[selectedOrder.priority] || 'text-slate-800'">
              {{ priorityLabels[selectedOrder.priority] || selectedOrder.priority }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500">Filial:</span>
            <span class="font-medium text-slate-900">{{ selectedOrder.branch?.name ?? '—' }}</span>
          </div>
        </div>

        <!-- Cliente & Aparelho -->
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="rounded-lg border border-slate-200 p-4">
            <h3 class="mb-2 font-semibold text-slate-900">Cliente</h3>
            <p class="font-medium text-indigo-700">{{ selectedOrder.customer?.name ?? 'Não informado' }}</p>
            <p v-if="selectedOrder.customer?.document" class="mt-1 text-slate-600">Doc: {{ selectedOrder.customer.document }}</p>
            <p v-if="selectedOrder.customer?.phone" class="mt-1 text-slate-600">Telefone: {{ selectedOrder.customer.phone }}</p>
            <p v-if="selectedOrder.customer?.email" class="mt-1 text-slate-600">E-mail: {{ selectedOrder.customer.email }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 p-4">
            <h3 class="mb-2 font-semibold text-slate-900">Aparelho / Equipamento</h3>
            <p class="font-medium text-slate-900">{{ selectedOrder.device_brand ? `${selectedOrder.device_brand} ` : '' }}{{ selectedOrder.device_model }}</p>
            <p v-if="selectedOrder.device_imei_serial" class="mt-1 text-slate-600">IMEI/Serial: {{ selectedOrder.device_imei_serial }}</p>
            <p v-if="selectedOrder.device_color" class="mt-1 text-slate-600">Cor: {{ selectedOrder.device_color }}</p>
            <p v-if="selectedOrder.device_password" class="mt-1 text-slate-600">Senha: {{ selectedOrder.device_password }}</p>
            <p v-if="selectedOrder.device_condition" class="mt-1 text-slate-600">Condições: {{ selectedOrder.device_condition }}</p>
            <p v-if="selectedOrder.accessories" class="mt-1 text-slate-600">Acessórios: {{ selectedOrder.accessories }}</p>
          </div>
        </div>

        <!-- Problema & Diagnóstico -->
        <div class="rounded-lg border border-slate-200 p-4 space-y-3">
          <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Defeito Relatado</h4>
            <p class="mt-1 text-slate-900">{{ selectedOrder.defect_reported }}</p>
          </div>
          <div v-if="selectedOrder.defect_diagnosed">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Diagnóstico Técnico</h4>
            <p class="mt-1 text-slate-900">{{ selectedOrder.defect_diagnosed }}</p>
          </div>
          <div v-if="selectedOrder.solution_applied">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Solução Aplicada</h4>
            <p class="mt-1 text-slate-900">{{ selectedOrder.solution_applied }}</p>
          </div>
          <div v-if="selectedOrder.technical_notes">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Observações Técnicas</h4>
            <p class="mt-1 text-slate-600">{{ selectedOrder.technical_notes }}</p>
          </div>
        </div>

        <!-- Itens (Peças e Serviços) -->
        <div v-if="selectedOrder.items && selectedOrder.items.length > 0" class="rounded-lg border border-slate-200 overflow-hidden">
          <div class="bg-slate-50 px-4 py-2.5 font-semibold text-slate-900 border-b border-slate-200">
            Serviços e Peças Utilizadas
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead class="bg-slate-50 text-slate-500 uppercase tracking-wider border-b border-slate-200">
                <tr>
                  <th class="px-4 py-2">Tipo</th>
                  <th class="px-4 py-2">Descrição</th>
                  <th class="px-4 py-2 text-right">Qtd</th>
                  <th class="px-4 py-2 text-right">Unitário</th>
                  <th class="px-4 py-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="item in selectedOrder.items" :key="item.id">
                  <td class="px-4 py-2">
                    <span
                      class="inline-flex rounded px-1.5 py-0.5 text-[11px] font-medium"
                      :class="item.type === 'service' ? 'bg-indigo-50 text-indigo-700' : 'bg-emerald-50 text-emerald-700'"
                    >
                      {{ item.type === 'service' ? 'Serviço' : 'Peça' }}
                    </span>
                  </td>
                  <td class="px-4 py-2 text-slate-900 font-medium">{{ item.description }}</td>
                  <td class="px-4 py-2 text-right text-slate-600">{{ item.quantity }}</td>
                  <td class="px-4 py-2 text-right text-slate-600">{{ formatCurrency(item.unit_price) }}</td>
                  <td class="px-4 py-2 text-right font-semibold text-slate-900">{{ formatCurrency(item.total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Checklist e Fotos de Inspeção -->
        <div v-if="selectedOrder.checklists && selectedOrder.checklists.length > 0" class="rounded-lg border border-slate-200 overflow-hidden">
          <div class="bg-slate-50 px-4 py-2.5 font-semibold text-slate-900 border-b border-slate-200 flex items-center justify-between">
            <span>Checklist Técnico e Fotos</span>
            <span class="text-xs font-normal text-slate-500">{{ selectedOrder.checklists.length }} itens verificados</span>
          </div>
          <div class="divide-y divide-slate-100 max-h-60 overflow-y-auto p-3 space-y-2">
            <div
              v-for="chk in selectedOrder.checklists"
              :key="chk.id"
              class="flex flex-col gap-1.5 py-1.5 text-xs"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                  <span
                    class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold"
                    :class="chk.stage === 'entry' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'"
                  >
                    {{ chk.stage === 'entry' ? 'Entrada' : 'Saída' }}
                  </span>
                  <span class="font-medium text-slate-900">{{ chk.item_name }}</span>
                  <span v-if="chk.category" class="text-[11px] text-slate-500">({{ chk.category }})</span>
                </div>
                <div class="flex items-center gap-2">
                  <span
                    class="rounded px-2 py-0.5 text-[11px] font-semibold"
                    :class="{
                      'bg-emerald-100 text-emerald-800': chk.status === 'ok',
                      'bg-red-100 text-red-800': chk.status === 'defect',
                      'bg-amber-100 text-amber-800': chk.status === 'not_tested',
                      'bg-slate-100 text-slate-800': chk.status === 'not_applicable',
                    }"
                  >
                    {{ chk.status === 'ok' ? '✓ OK' : chk.status === 'defect' ? '✕ Defeito' : chk.status === 'not_tested' ? 'Pendente' : 'N/A' }}
                  </span>
                </div>
              </div>

              <p v-if="chk.notes" class="text-[11px] text-slate-600 italic">
                Obs: {{ chk.notes }}
              </p>

              <!-- Fotos anexadas -->
              <div v-if="chk.images && chk.images.length > 0" class="flex flex-wrap items-center gap-1.5 mt-1">
                <div
                  v-for="img in chk.images"
                  :key="img.id"
                  class="group relative h-10 w-10 rounded-md border border-slate-200 bg-white overflow-hidden shadow-xs cursor-pointer flex-shrink-0"
                  :title="img.original_name ?? 'Ver foto'"
                  @click="openPhotoPreview(img.url ?? '', img.original_name ?? 'Foto')"
                >
                  <img
                    :src="img.url ?? ''"
                    :alt="img.original_name ?? 'Foto do checklist'"
                    class="h-full w-full object-cover transition-transform group-hover:scale-105"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Totais e Datas -->
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="rounded-lg border border-slate-200 p-4 space-y-1.5 text-xs text-slate-600">
            <p><strong>Aberto em:</strong> {{ formatDate(selectedOrder.opened_at) }}</p>
            <p><strong>Previsão de conclusão:</strong> {{ formatDate(selectedOrder.expected_at) }}</p>
            <p v-if="selectedOrder.completed_at"><strong>Concluído em:</strong> {{ formatDate(selectedOrder.completed_at) }}</p>
            <p v-if="selectedOrder.delivered_at"><strong>Entregue em:</strong> {{ formatDate(selectedOrder.delivered_at) }}</p>
            <p v-if="selectedOrder.technician"><strong>Técnico:</strong> {{ selectedOrder.technician.name }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-1.5 text-right">
            <p class="text-xs text-slate-600">Serviços: <span class="font-medium text-slate-900">{{ formatCurrency(selectedOrder.services_amount) }}</span></p>
            <p class="text-xs text-slate-600">Peças / Produtos: <span class="font-medium text-slate-900">{{ formatCurrency(selectedOrder.products_amount) }}</span></p>
            <p v-if="Number(selectedOrder.discount) > 0" class="text-xs text-red-600">Desconto: -{{ formatCurrency(selectedOrder.discount) }}</p>
            <p class="pt-2 text-base font-bold text-slate-900 border-t border-slate-200">
              Total: {{ formatCurrency(selectedOrder.total) }}
            </p>
          </div>
        </div>

        <!-- Termos de Garantia -->
        <div v-if="selectedOrder.warranty_terms" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
          <strong>Termos de garantia:</strong> {{ selectedOrder.warranty_terms }}
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-slate-200">
          <button
            v-if="canPerformAction(selectedOrder.status, 'cancel')"
            type="button"
            class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 transition-colors hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500"
            @click="promptCancelFromModal"
          >
            Cancelar ordem de serviço
          </button>
          <button
            v-if="canPerformAction(selectedOrder.status, 'execute')"
            type="button"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
            @click="openExecuteFromModal"
          >
            Executar ordem de serviço
          </button>
          <RouterLink
            v-if="canPerformAction(selectedOrder.status, 'edit')"
            :to="`/crm/ordens-de-servico/${selectedOrder.id}/editar`"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
          >
            Editar ordem de serviço
          </RouterLink>
          <button
            type="button"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="viewModalOpen = false"
          >
            Fechar
          </button>
        </div>
      </div>
    </Modal>

    <!-- Modal de Execução da OS -->
    <Modal
      v-model="executeModalOpen"
      :title="`Executar Ordem de Serviço ${executingOrder?.number ?? ''}`"
      description="Registre o técnico responsável, laudo de diagnóstico, solução aplicada e checklist técnico."
      max-width-class="max-w-2xl"
      @update:model-value="(isOpen) => !isOpen && closeExecuteModal()"
    >
      <div v-if="executingOrder" class="space-y-5">
        <!-- Aparelho e Resumo do Defeito -->
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-700">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-2">
            <div>
              <span class="font-semibold text-slate-900">Aparelho:</span>
              {{ executingOrder.device_brand ? executingOrder.device_brand + ' ' : '' }}{{ executingOrder.device_model }}
              <span v-if="executingOrder.device_color" class="text-slate-500">({{ executingOrder.device_color }})</span>
            </div>
            <div>
              <span class="font-semibold text-slate-900">Cliente:</span>
              {{ executingOrder.customer?.name ?? 'Não informado' }}
            </div>
          </div>
          <div class="pt-2">
            <span class="font-semibold text-slate-900">Defeito relatado:</span>
            <span class="text-slate-800">{{ executingOrder.defect_reported }}</span>
          </div>
        </div>

        <form class="space-y-4" @submit.prevent="saveExecution">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="exec-tech" class="block text-sm font-medium text-slate-700">Técnico Responsável</label>
              <SearchableCombobox
                id="exec-tech"
                v-model="executeForm.technician_id"
                :options="technicianOptions"
                placeholder="Selecione o técnico..."
                class="mt-1"
              />
            </div>

            <div>
              <label for="exec-status" class="block text-sm font-medium text-slate-700">Status da Execução *</label>
              <SearchableCombobox
                id="exec-status"
                v-model="executeForm.status"
                :options="availableExecuteStatusOptions"
                placeholder="Selecione o status..."
                required
                class="mt-1"
              />
            </div>
          </div>

          <div>
            <label for="exec-diagnosis" class="block text-sm font-medium text-slate-700">Diagnóstico Técnico / Laudo de Entrada</label>
            <textarea
              id="exec-diagnosis"
              v-model="executeForm.defect_diagnosed"
              rows="2"
              placeholder="Descreva o problema constatado após testes na bancada..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <div>
            <label for="exec-solution" class="block text-sm font-medium text-slate-700">Solução Aplicada / Reparo Realizado</label>
            <textarea
              id="exec-solution"
              v-model="executeForm.solution_applied"
              rows="2"
              placeholder="Descreva os procedimentos efetuados, ressoldas, ajustes ou reparos realizados..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <div>
            <label for="exec-notes" class="block text-sm font-medium text-slate-700">Observações Técnicas Internas</label>
            <textarea
              id="exec-notes"
              v-model="executeForm.technical_notes"
              rows="2"
              placeholder="Anotações internas, condições de bateria, observações de garantia..."
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            ></textarea>
          </div>

          <!-- Checklist de Execução -->
          <div v-if="executeForm.checklists.length > 0" class="rounded-lg border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-3.5 py-2 text-xs font-semibold uppercase tracking-wider text-slate-700 border-b border-slate-200">
              Checklist de Testes da OS
            </div>
            <div class="divide-y divide-slate-100 max-h-48 overflow-y-auto p-2">
              <div
                v-for="chk in executeForm.checklists"
                :key="chk.id || chk.item_name"
                class="py-2 px-1 text-xs space-y-1.5"
              >
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div>
                    <span class="font-medium text-slate-900">{{ chk.item_name }}</span>
                    <span v-if="chk.category" class="ml-1 text-[11px] text-slate-500">({{ chk.category }})</span>
                  </div>
                  <div class="flex items-center gap-1">
                    <button
                      type="button"
                      :class="[
                        'rounded px-2 py-0.5 text-[11px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                        chk.status === 'ok' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      ]"
                      @click="setChecklistStatus(chk, 'ok')"
                    >
                      OK
                    </button>
                    <button
                      type="button"
                      :class="[
                        'rounded px-2 py-0.5 text-[11px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                        chk.status === 'defect' ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      ]"
                      @click="setChecklistStatus(chk, 'defect')"
                    >
                      Defeito
                    </button>
                    <button
                      type="button"
                      :class="[
                        'rounded px-2 py-0.5 text-[11px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                        chk.status === 'not_tested' ? 'bg-slate-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      ]"
                      @click="setChecklistStatus(chk, 'not_tested')"
                    >
                      Pendente
                    </button>
                    <button
                      type="button"
                      :class="[
                        'rounded px-2 py-0.5 text-[11px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                        chk.status === 'not_applicable' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      ]"
                      @click="setChecklistStatus(chk, 'not_applicable')"
                    >
                      N/A
                    </button>
                  </div>
                </div>

                <!-- Fotos do checklist na execução -->
                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                  <div
                    v-for="img in chk.images"
                    :key="img.id"
                    class="group relative h-8 w-8 rounded border border-slate-200 bg-white overflow-hidden shadow-xs cursor-pointer flex-shrink-0"
                    :title="img.original_name ?? 'Ver foto'"
                    @click="openPhotoPreview(img.url ?? '', img.original_name ?? 'Foto')"
                  >
                    <img
                      :src="img.url ?? ''"
                      :alt="img.original_name ?? 'Foto do checklist'"
                      class="h-full w-full object-cover transition-transform group-hover:scale-105"
                    />
                    <button
                      type="button"
                      class="absolute top-0 right-0 flex h-3.5 w-3.5 items-center justify-center rounded-bl bg-red-600 text-[8px] text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-700"
                      :aria-label="`Excluir imagem ${img.original_name ?? ''}`"
                      @click.stop="removeExecuteChecklistImage(chk, img)"
                    >
                      ✕
                    </button>
                  </div>

                  <label
                    v-if="chk.id"
                    :for="`exec-photo-${chk.id}`"
                    :class="[
                      'inline-flex items-center gap-1 rounded border border-dashed border-slate-300 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-600 hover:border-indigo-400 hover:text-indigo-600 cursor-pointer transition-colors',
                      chk.uploading ? 'opacity-50 pointer-events-none' : ''
                    ]"
                  >
                    <span>{{ chk.uploading ? '...' : '+ Foto' }}</span>
                    <input
                      :id="`exec-photo-${chk.id}`"
                      type="file"
                      accept="image/*"
                      multiple
                      class="sr-only"
                      @change="handleExecuteChecklistPhotoSelect(chk, $event)"
                    />
                  </label>

                  <span v-if="chk.id && executeImageError[chk.id]" class="text-[10px] text-red-600 font-medium">
                    {{ executeImageError[chk.id] }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="executeError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ executeError }}
          </div>

          <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
            <button
              type="button"
              class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
              @click="closeExecuteModal"
            >
              Cancelar
            </button>
            <button
              id="btn-save-execution"
              type="submit"
              :disabled="executingSaving"
              class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
            >
              {{ executingSaving ? 'Salvando...' : 'Salvar execução' }}
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Diálogo de Cancelamento -->
    <ConfirmationDialog
      v-model="cancelConfirmationOpen"
      title="Cancelar ordem de serviço"
      :message="`Tem certeza que deseja cancelar a ordem de serviço ${pendingCancellation?.number ?? ''}? Esta ação não poderá ser desfeita.`"
      confirm-label="Cancelar ordem de serviço"
      cancel-label="Voltar"
      :busy="cancellingId !== null"
      @confirm="confirmCancel"
    />

    <!-- Modal de visualização de foto ampliada -->
    <Modal
      v-model="photoModalOpen"
      title="Visualização da Foto"
      max-width-class="max-w-3xl"
    >
      <div class="flex flex-col items-center p-2">
        <img
          v-if="previewingImage"
          :src="previewingImage.url"
          :alt="previewingImage.name || 'Foto do checklist'"
          class="max-h-[70vh] w-auto max-w-full rounded-lg object-contain shadow-sm"
        />
        <p v-if="previewingImage?.name" class="mt-2 text-xs text-slate-500">
          {{ previewingImage.name }}
        </p>
      </div>
    </Modal>
  </section>
</template>
