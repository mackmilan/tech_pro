<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

export interface ServiceOrderStatusRule {
  id: string;
  status: string;
  name: string;
  description: string | null;
  color: string | null;
  allow_view: boolean;
  allow_edit: boolean;
  allow_execute: boolean;
  allow_cancel: boolean;
  allow_print: boolean;
  allowed_transitions: string[] | null;
  active: boolean;
}

const standardStatusOptions: ComboboxOption[] = [
  { value: 'open', label: 'Aberta (open)' },
  { value: 'in_analysis', label: 'Em análise (in_analysis)' },
  { value: 'approved', label: 'Aprovada (approved)' },
  { value: 'in_progress', label: 'Em andamento (in_progress)' },
  { value: 'waiting_parts', label: 'Aguardando peças (waiting_parts)' },
  { value: 'completed', label: 'Concluída (completed)' },
  { value: 'delivered', label: 'Entregue (delivered)' },
  { value: 'cancelled', label: 'Cancelada (cancelled)' },
  { value: 'custom', label: 'Personalizado (digitar código do status)' },
];

const standardStatusMap: Record<string, string> = {
  open: 'Aberta',
  in_analysis: 'Em análise',
  approved: 'Aprovada',
  in_progress: 'Em andamento',
  waiting_parts: 'Aguardando peças',
  completed: 'Concluída',
  delivered: 'Entregue',
  cancelled: 'Cancelada',
};

const colorOptions: ComboboxOption[] = [
  { value: 'slate', label: 'Cinza neutro (Slate)' },
  { value: 'amber', label: 'Âmbar / Amarelo (Amber)' },
  { value: 'blue', label: 'Azul (Blue)' },
  { value: 'indigo', label: 'Índigo / Roxo (Indigo)' },
  { value: 'orange', label: 'Laranja (Orange)' },
  { value: 'emerald', label: 'Esmeralda (Emerald)' },
  { value: 'green', label: 'Verde (Green)' },
  { value: 'red', label: 'Vermelho (Red)' },
];

const colorClasses: Record<string, string> = {
  slate: 'bg-slate-100 text-slate-700 border-slate-200',
  amber: 'bg-amber-50 text-amber-800 border-amber-200',
  blue: 'bg-blue-50 text-blue-700 border-blue-200',
  indigo: 'bg-indigo-50 text-indigo-700 border-indigo-200',
  orange: 'bg-orange-50 text-orange-800 border-orange-200',
  emerald: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  green: 'bg-green-100 text-green-900 border-green-300',
  red: 'bg-red-50 text-red-700 border-red-200',
};

const activeFilterOptions: ComboboxOption[] = [
  { value: 'todos', label: 'Todos os status' },
  { value: '1', label: 'Apenas ativas' },
  { value: '0', label: 'Apenas inativas' },
];

const rules = ref<ServiceOrderStatusRule[]>([]);
const loading = ref(false);
const error = ref('');
const successMessage = ref('');
const search = ref('');
const activeFilter = ref('todos');

const isModalOpen = ref(false);
const isEditing = ref(false);
const editingId = ref<string | null>(null);
const saving = ref(false);
const modalError = ref('');

const selectedStatusPreset = ref('open');
const isCustomStatus = ref(false);

const form = ref({
  status: 'open',
  name: 'Aberta',
  description: '',
  color: 'slate',
  allow_view: true,
  allow_edit: true,
  allow_execute: true,
  allow_cancel: true,
  allow_print: true,
  allowed_transitions: [] as string[],
  active: true,
});

const isDeleteDialogOpen = ref(false);
const ruleToDelete = ref<ServiceOrderStatusRule | null>(null);
const deleting = ref(false);

const isRestoreDefaultsDialogOpen = ref(false);
const restoringDefaults = ref(false);

const columns: DataGridColumn[] = [
  { key: 'status', label: 'Status' },
  { key: 'name', label: 'Nome da Regra' },
  { key: 'actions_visibility', label: 'Ações Visíveis na Lista' },
  { key: 'allowed_transitions', label: 'Transições Permitidas (Máquina de Estados)' },
  { key: 'active', label: 'Ativo' },
];

const rows = computed(() =>
  rules.value.map((rule) => ({
    id: rule.id,
    status: rule.status,
    name: rule.name,
    description: rule.description,
    color: rule.color ?? 'slate',
    allow_view: rule.allow_view,
    allow_edit: rule.allow_edit,
    allow_execute: rule.allow_execute,
    allow_cancel: rule.allow_cancel,
    allow_print: rule.allow_print,
    allowed_transitions: rule.allowed_transitions ?? [],
    active: rule.active,
    rule,
  }))
);

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadRules() {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams();
    if (search.value.trim()) params.append('search', search.value.trim());
    if (activeFilter.value && activeFilter.value !== 'todos') params.append('active', activeFilter.value);
    params.append('per_page', '100');

    const response = await fetch(`/api/v1/service-order-status-rules?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível carregar as regras de status da OS.');
    const payload = await response.json() as { data: ServiceOrderStatusRule[] };
    rules.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar regras.';
  } finally {
    loading.value = false;
  }
}

let searchDebounceTimeout: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
  if (searchDebounceTimeout) clearTimeout(searchDebounceTimeout);
  searchDebounceTimeout = setTimeout(() => {
    loadRules();
  }, 300);
});

watch(activeFilter, () => {
  loadRules();
});

watch(selectedStatusPreset, (newVal) => {
  if (isEditing.value) return;
  if (newVal === 'custom') {
    isCustomStatus.value = true;
    form.value.status = '';
    form.value.name = '';
  } else {
    isCustomStatus.value = false;
    form.value.status = newVal;
    form.value.name = standardStatusMap[newVal] ?? newVal;
  }
});

function openCreateModal() {
  isEditing.value = false;
  editingId.value = null;
  modalError.value = '';
  selectedStatusPreset.value = 'open';
  isCustomStatus.value = false;
  form.value = {
    status: 'open',
    name: 'Aberta',
    description: '',
    color: 'slate',
    allow_view: true,
    allow_edit: true,
    allow_execute: true,
    allow_cancel: true,
    allow_print: true,
    allowed_transitions: ['in_analysis', 'approved', 'in_progress', 'cancelled'],
    active: true,
  };
  isModalOpen.value = true;
}

function openEditModal(rule: ServiceOrderStatusRule) {
  isEditing.value = true;
  editingId.value = rule.id;
  modalError.value = '';
  const isStandard = Object.keys(standardStatusMap).includes(rule.status);
  selectedStatusPreset.value = isStandard ? rule.status : 'custom';
  isCustomStatus.value = !isStandard;

  form.value = {
    status: rule.status,
    name: rule.name,
    description: rule.description ?? '',
    color: rule.color ?? 'slate',
    allow_view: Boolean(rule.allow_view),
    allow_edit: Boolean(rule.allow_edit),
    allow_execute: Boolean(rule.allow_execute),
    allow_cancel: Boolean(rule.allow_cancel),
    allow_print: Boolean(rule.allow_print),
    allowed_transitions: Array.isArray(rule.allowed_transitions) ? [...rule.allowed_transitions] : [],
    active: Boolean(rule.active),
  };
  isModalOpen.value = true;
}

function toggleTransition(statusKey: string) {
  const current = form.value.allowed_transitions;
  if (current.includes(statusKey)) {
    form.value.allowed_transitions = current.filter((s) => s !== statusKey);
  } else {
    form.value.allowed_transitions = [...current, statusKey];
  }
}

async function saveRule() {
  if (!form.value.status.trim()) {
    modalError.value = 'O código do status é obrigatório.';
    return;
  }
  if (!form.value.name.trim()) {
    modalError.value = 'O nome de exibição é obrigatório.';
    return;
  }

  saving.value = true;
  modalError.value = '';

  const payload = {
    status: form.value.status.trim().toLowerCase(),
    name: form.value.name.trim(),
    description: form.value.description.trim() || null,
    color: form.value.color || 'slate',
    allow_view: form.value.allow_view,
    allow_edit: form.value.allow_edit,
    allow_execute: form.value.allow_execute,
    allow_cancel: form.value.allow_cancel,
    allow_print: form.value.allow_print,
    allowed_transitions: form.value.allowed_transitions,
    active: form.value.active,
  };

  try {
    const url = isEditing.value && editingId.value
      ? `/api/v1/service-order-status-rules/${editingId.value}`
      : '/api/v1/service-order-status-rules';
    const method = isEditing.value ? 'PUT' : 'POST';

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

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a regra de status.');

    isModalOpen.value = false;
    successMessage.value = isEditing.value
      ? 'Regra de status atualizada com sucesso.'
      : 'Nova regra de status criada com sucesso.';
    setTimeout(() => {
      successMessage.value = '';
    }, 4000);
    await loadRules();
  } catch (exception) {
    modalError.value = exception instanceof Error ? exception.message : 'Erro ao salvar regra.';
  } finally {
    saving.value = false;
  }
}

function promptDelete(rule: ServiceOrderStatusRule) {
  ruleToDelete.value = rule;
  isDeleteDialogOpen.value = true;
}

async function confirmDelete() {
  if (!ruleToDelete.value) return;

  deleting.value = true;
  try {
    const response = await fetch(`/api/v1/service-order-status-rules/${ruleToDelete.value.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível excluir a regra.');

    isDeleteDialogOpen.value = false;
    ruleToDelete.value = null;
    successMessage.value = 'Regra de status excluída com sucesso.';
    setTimeout(() => {
      successMessage.value = '';
    }, 4000);
    await loadRules();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao excluir regra.';
  } finally {
    deleting.value = false;
  }
}

function promptRestoreDefaults() {
  isRestoreDefaultsDialogOpen.value = true;
}

async function confirmRestoreDefaults() {
  restoringDefaults.value = true;
  try {
    const response = await fetch('/api/v1/service-order-status-rules/seed-defaults', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível restaurar os padrões.');

    isRestoreDefaultsDialogOpen.value = false;
    successMessage.value = 'Regras de status restauradas para os padrões com sucesso.';
    setTimeout(() => {
      successMessage.value = '';
    }, 4000);
    await loadRules();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro ao restaurar padrões.';
  } finally {
    restoringDefaults.value = false;
  }
}

onMounted(() => {
  loadRules();
});
</script>

<template>
  <section class="w-full">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink to="/configuracoes" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Configurações</RouterLink>
          <span aria-hidden="true">/</span>
          <span class="font-medium text-slate-900">Máquina de estados da OS</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Máquina de Estados de Ordens de Serviço</h1>
        <p class="mt-1 text-sm text-slate-500">Configure a visibilidade das ações na lista de ordens de serviço e o fluxo de transição entre status.</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
          @click="promptRestoreDefaults"
        >
          <svg class="-ml-0.5 mr-2 h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Restaurar padrões
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
          @click="openCreateModal"
        >
          <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Nova regra de status
        </button>
      </div>
    </header>

    <div v-if="successMessage" role="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
      {{ successMessage }}
    </div>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Filtros -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="lg:col-span-2">
          <label for="filter-search" class="block text-sm font-medium text-slate-700">Buscar</label>
          <input
            id="filter-search"
            v-model="search"
            type="text"
            placeholder="Buscar por status, nome ou descrição..."
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label for="filter-active" class="block text-sm font-medium text-slate-700">Situação</label>
          <SearchableCombobox
            id="filter-active"
            v-model="activeFilter"
            :options="activeFilterOptions"
            placeholder="Filtrar situação..."
            class="mt-1"
          />
        </div>
      </div>
    </div>

    <!-- Tabela DataGrid -->
    <DataGrid
      :columns="columns"
      :rows="rows"
      :loading="loading"
      empty-message="Nenhuma regra de status cadastrada."
    >
      <template #cell-status="{ row }">
        <div class="flex items-center gap-2">
          <span
            class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold"
            :class="colorClasses[(row.color as string)] || colorClasses.slate"
          >
            {{ row.status }}
          </span>
        </div>
      </template>

      <template #cell-name="{ row }">
        <div>
          <span class="font-medium text-slate-900">{{ row.name }}</span>
          <p v-if="row.description" class="text-xs text-slate-500">{{ row.description }}</p>
        </div>
      </template>

      <template #cell-actions_visibility="{ row }">
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
          <span
            class="inline-flex items-center rounded px-2 py-0.5 font-medium border"
            :class="row.allow_view ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-slate-50 text-slate-400 border-slate-200 line-through opacity-60'"
            title="Visualizar detalhes"
          >
            Visualizar
          </span>
          <span
            class="inline-flex items-center rounded px-2 py-0.5 font-medium border"
            :class="row.allow_edit ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-slate-50 text-slate-400 border-slate-200 line-through opacity-60'"
            title="Editar OS"
          >
            Editar
          </span>
          <span
            class="inline-flex items-center rounded px-2 py-0.5 font-medium border"
            :class="row.allow_execute ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-400 border-slate-200 line-through opacity-60'"
            title="Executar OS / Apontamento técnico"
          >
            Executar
          </span>
          <span
            class="inline-flex items-center rounded px-2 py-0.5 font-medium border"
            :class="row.allow_cancel ? 'bg-red-50 text-red-700 border-red-200' : 'bg-slate-50 text-slate-400 border-slate-200 line-through opacity-60'"
            title="Cancelar OS"
          >
            Cancelar
          </span>
          <span
            class="inline-flex items-center rounded px-2 py-0.5 font-medium border"
            :class="row.allow_print ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-slate-50 text-slate-400 border-slate-200 line-through opacity-60'"
            title="Imprimir comprovante"
          >
            Imprimir
          </span>
        </div>
      </template>

      <template #cell-allowed_transitions="{ row }">
        <div class="flex flex-wrap items-center gap-1 text-xs">
          <template v-if="(row.allowed_transitions as string[]).length > 0">
            <span
              v-for="trans in (row.allowed_transitions as string[])"
              :key="trans"
              class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-700 border border-slate-200"
            >
              {{ standardStatusMap[trans] ?? trans }}
            </span>
          </template>
          <span v-else class="text-xs text-slate-400 italic">Estado final (sem próximas transições)</span>
        </div>
      </template>

      <template #cell-active="{ row }">
        <span
          class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
          :class="row.active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200'"
        >
          {{ row.active ? 'Ativo' : 'Inativo' }}
        </span>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <button
            type="button"
            title="Editar regra"
            aria-label="Editar regra"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-slate-100 hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="openEditModal(row.rule as ServiceOrderStatusRule)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </button>

          <button
            type="button"
            title="Excluir regra"
            aria-label="Excluir regra"
            class="rounded p-1 text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500"
            @click="promptDelete(row.rule as ServiceOrderStatusRule)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <!-- Modal de Criar / Editar Regra -->
    <Modal
      v-model="isModalOpen"
      :title="isEditing ? 'Editar Regra de Status da OS' : 'Nova Regra de Status da OS'"
      description="Defina a visibilidade das ações e as transições de status válidas para a ordem de serviço."
      max-width-class="max-w-2xl"
    >
      <form class="space-y-5" @submit.prevent="saveRule">
        <div v-if="modalError" role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
          {{ modalError }}
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <!-- Status Preset / Selection -->
          <div>
            <label for="rule-status-preset" class="block text-sm font-medium text-slate-700">
              Status da Ordem de Serviço <span class="text-red-500">*</span>
            </label>
            <SearchableCombobox
              id="rule-status-preset"
              v-model="selectedStatusPreset"
              :options="standardStatusOptions"
              :disabled="isEditing"
              placeholder="Selecione o status..."
              class="mt-1"
            />
          </div>

          <!-- Custom Status Code if selected -->
          <div v-if="isCustomStatus || isEditing">
            <label for="rule-status-code" class="block text-sm font-medium text-slate-700">
              Código identificador do status <span class="text-red-500">*</span>
            </label>
            <input
              id="rule-status-code"
              v-model="form.status"
              type="text"
              placeholder="ex: aguardando_orcamento"
              :disabled="isEditing"
              required
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-500"
            />
          </div>

          <!-- Nome da Regra -->
          <div>
            <label for="rule-name" class="block text-sm font-medium text-slate-700">
              Nome de exibição <span class="text-red-500">*</span>
            </label>
            <input
              id="rule-name"
              v-model="form.name"
              type="text"
              placeholder="ex: Em diagnóstico"
              required
              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <!-- Cor Visual do Status -->
          <div>
            <label for="rule-color" class="block text-sm font-medium text-slate-700">
              Cor do badge
            </label>
            <SearchableCombobox
              id="rule-color"
              v-model="form.color"
              :options="colorOptions"
              placeholder="Selecione a cor..."
              class="mt-1"
            />
          </div>
        </div>

        <!-- Descrição -->
        <div>
          <label for="rule-description" class="block text-sm font-medium text-slate-700">Descrição / Instruções do estado</label>
          <textarea
            id="rule-description"
            v-model="form.description"
            rows="2"
            placeholder="Descreva o que acontece nesta etapa da ordem de serviço..."
            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          ></textarea>
        </div>

        <!-- Seção: Visibilidade de Ações -->
        <fieldset class="rounded-lg border border-slate-200 p-4">
          <legend class="px-2 text-sm font-semibold text-slate-900">
            Visibilidade de Ações na Lista de Ordens de Serviço
          </legend>
          <p class="text-xs text-slate-500 mb-3">Marque quais botões de ação ficam visíveis para uma OS quando estiver neste status:</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex items-start gap-2.5 cursor-pointer">
              <input
                v-model="form.allow_view"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span class="text-sm font-medium text-slate-800">Visualizar detalhes</span>
                <p class="text-xs text-slate-500">Exibir botão para abrir modal com detalhes completos da OS.</p>
              </div>
            </label>

            <label class="flex items-start gap-2.5 cursor-pointer">
              <input
                v-model="form.allow_edit"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span class="text-sm font-medium text-slate-800">Editar ordem de serviço</span>
                <p class="text-xs text-slate-500">Permitir navegar para o formulário de edição da OS.</p>
              </div>
            </label>

            <label class="flex items-start gap-2.5 cursor-pointer">
              <input
                v-model="form.allow_execute"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span class="text-sm font-medium text-slate-800">Executar / Apontamento técnico</span>
                <p class="text-xs text-slate-500">Exibir botão de execução técnica, checklists e laudo.</p>
              </div>
            </label>

            <label class="flex items-start gap-2.5 cursor-pointer">
              <input
                v-model="form.allow_cancel"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span class="text-sm font-medium text-slate-800">Cancelar ordem de serviço</span>
                <p class="text-xs text-slate-500">Permitir ação de cancelamento com diálogo de confirmação.</p>
              </div>
            </label>

            <label class="flex items-start gap-2.5 cursor-pointer sm:col-span-2">
              <input
                v-model="form.allow_print"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <div>
                <span class="text-sm font-medium text-slate-800">Imprimir comprovante / OS</span>
                <p class="text-xs text-slate-500">Permitir gerar visualização para impressão ou entrega.</p>
              </div>
            </label>
          </div>
        </fieldset>

        <!-- Seção: Máquina de Estados / Transições Permitidas -->
        <fieldset class="rounded-lg border border-slate-200 p-4">
          <legend class="px-2 text-sm font-semibold text-slate-900">
            Máquina de Estados: Transições de Status Permitidas
          </legend>
          <p class="text-xs text-slate-500 mb-3">
            Escolha para quais status uma OS neste estado pode avançar ou transicionar:
          </p>
          <div class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="preset in standardStatusOptions.filter((o) => o.value !== 'custom' && o.value !== form.status)"
              :key="preset.value"
              class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-xs transition-colors hover:bg-slate-50 cursor-pointer"
            >
              <input
                type="checkbox"
                :checked="form.allowed_transitions.includes(preset.value)"
                class="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                @change="toggleTransition(preset.value)"
              />
              <span class="font-medium text-slate-800">{{ preset.label }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Ativo -->
        <div class="flex items-center gap-2 pt-1">
          <input
            id="rule-active"
            v-model="form.active"
            type="checkbox"
            class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
          />
          <label for="rule-active" class="text-sm font-medium text-slate-800">
            Regra ativa no sistema
          </label>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
          <button
            type="button"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @click="isModalOpen = false"
          >
            Cancelar
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-50"
          >
            {{ saving ? 'Salvando...' : (isEditing ? 'Salvar alterações' : 'Criar regra') }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Diálogo de Exclusão -->
    <ConfirmationDialog
      v-model="isDeleteDialogOpen"
      title="Excluir Regra de Status"
      :message="`Tem certeza que deseja excluir a regra de visibilidade para o status '${ruleToDelete?.name ?? ''}' (${ruleToDelete?.status ?? ''})?`"
      confirm-label="Excluir regra"
      :busy="deleting"
      @confirm="confirmDelete"
    />

    <!-- Diálogo de Restaurar Padrões -->
    <ConfirmationDialog
      v-model="isRestoreDefaultsDialogOpen"
      title="Restaurar Regras Padrão da Máquina de Estados"
      message="Esta ação restaurará as configurações padrão de visibilidade e transições para os 8 status padrão de ordem de serviço. Deseja continuar?"
      confirm-label="Restaurar padrões"
      :busy="restoringDefaults"
      @confirm="confirmRestoreDefaults"
    />
  </section>
</template>

