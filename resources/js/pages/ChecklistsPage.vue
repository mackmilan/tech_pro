<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

interface Service {
  id: string;
  name: string;
  code: string | null;
}

interface ServiceChecklist {
  id: string;
  service_id: string | null;
  name: string;
  category: string | null;
  description: string | null;
  stage: 'entry' | 'exit' | 'both';
  is_required: boolean;
  sort_order: number;
  active: boolean;
  service?: Service | null;
}

const checklists = ref<ServiceChecklist[]>([]);
const services = ref<Service[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const checklistPendingDeletion = ref<ServiceChecklist | null>(null);
const editingId = ref<string | null>(null);

const search = ref('');
const stageFilter = ref('');
const statusFilter = ref('');

const stageFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todas as etapas' },
  { value: 'entry', label: 'Entrada do aparelho' },
  { value: 'exit', label: 'Saída / Conclusão' },
  { value: 'both', label: 'Ambas as etapas' },
];

const statusFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todos os status' },
  { value: 'active', label: 'Somente ativos' },
  { value: 'inactive', label: 'Somente inativos' },
];

const formStageOptions: ComboboxOption[] = [
  { value: 'both', label: 'Ambas as etapas (Entrada e Saída)' },
  { value: 'entry', label: 'Apenas na Entrada' },
  { value: 'exit', label: 'Apenas na Saída / Conclusão' },
];

const form = ref({
  name: '',
  category: '',
  service_id: '',
  description: '',
  stage: 'both' as 'entry' | 'exit' | 'both',
  is_required: false,
  sort_order: 0,
  active: true,
});

const serviceOptions = computed<ComboboxOption[]>(() => [
  { value: '', label: 'Todos os serviços (Geral para qualquer aparelho)' },
  ...services.value.map((s) => ({
    value: s.id,
    label: s.code ? `[${s.code}] ${s.name}` : s.name,
  })),
]);

const columns: DataGridColumn[] = [
  { key: 'sort_order', label: 'Ordem' },
  { key: 'name', label: 'Item de Verificação' },
  { key: 'category', label: 'Categoria' },
  { key: 'stage', label: 'Etapa' },
  { key: 'service_name', label: 'Serviço Específico' },
  { key: 'is_required', label: 'Obrigatório' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];

function stageLabel(stage: 'entry' | 'exit' | 'both'): string {
  if (stage === 'entry') return 'Entrada';
  if (stage === 'exit') return 'Saída';
  return 'Ambas';
}

const filteredChecklists = computed(() => {
  return checklists.value.filter((item) => {
    if (statusFilter.value === 'active' && !item.active) return false;
    if (statusFilter.value === 'inactive' && item.active) return false;
    if (stageFilter.value) {
      if (stageFilter.value === 'entry' && item.stage !== 'entry' && item.stage !== 'both') return false;
      if (stageFilter.value === 'exit' && item.stage !== 'exit' && item.stage !== 'both') return false;
      if (stageFilter.value === 'both' && item.stage !== 'both') return false;
    }
    if (search.value.trim()) {
      const q = search.value.trim().toLowerCase();
      const matchName = item.name.toLowerCase().includes(q);
      const matchCat = item.category?.toLowerCase().includes(q) ?? false;
      const matchDesc = item.description?.toLowerCase().includes(q) ?? false;
      if (!matchName && !matchCat && !matchDesc) return false;
    }
    return true;
  });
});

const rows = computed(() => filteredChecklists.value.map((chk) => ({
  id: chk.id,
  sort_order: chk.sort_order,
  name: chk.name,
  category: chk.category || 'Geral',
  stage: stageLabel(chk.stage),
  service_name: chk.service?.name || 'Geral (Todos)',
  is_required: chk.is_required ? 'Sim' : 'Opcional',
  status: chk.active ? 'Ativo' : 'Inativo',
  checklist: chk,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadChecklists() {
  loading.value = true;
  error.value = '';
  try {
    const [chkRes, srvRes] = await Promise.all([
      fetch('/api/v1/service-checklists?per_page=200', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      }),
      fetch('/api/v1/services?per_page=200&active=1', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      }),
    ]);

    if (!chkRes.ok) throw await responseError(chkRes, 'Não foi possível carregar os itens de checklist.');
    if (srvRes.ok) {
      const srvPayload = await srvRes.json() as { data: Service[] };
      services.value = srvPayload.data;
    }

    const payload = await chkRes.json() as { data: ServiceChecklist[] };
    checklists.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar checklists.';
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = {
    name: '',
    category: '',
    service_id: '',
    description: '',
    stage: 'both',
    is_required: false,
    sort_order: checklists.value.length + 1,
    active: true,
  };
  modalOpen.value = true;
}

function startEdit(chk: ServiceChecklist) {
  editingId.value = chk.id;
  formError.value = '';
  form.value = {
    name: chk.name,
    category: chk.category ?? '',
    service_id: chk.service_id ?? '',
    description: chk.description ?? '',
    stage: chk.stage,
    is_required: chk.is_required,
    sort_order: chk.sort_order,
    active: chk.active,
  };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
}

async function saveChecklist() {
  saving.value = true;
  formError.value = '';

  const payload = {
    name: form.value.name.trim(),
    category: form.value.category.trim() || null,
    service_id: form.value.service_id || null,
    description: form.value.description.trim() || null,
    stage: form.value.stage,
    is_required: form.value.is_required,
    sort_order: Number(form.value.sort_order) || 0,
    active: form.value.active,
  };

  try {
    const url = editingId.value ? `/api/v1/service-checklists/${editingId.value}` : '/api/v1/service-checklists';
    const method = editingId.value ? 'PUT' : 'POST';

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

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o item de checklist.');
    closeModal();
    await loadChecklists();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar item de checklist.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(chk: ServiceChecklist) {
  checklistPendingDeletion.value = chk;
  deleteConfirmationOpen.value = true;
}

async function deleteChecklist() {
  const chk = checklistPendingDeletion.value;
  if (!chk) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = chk.id;
  error.value = '';

  try {
    const response = await fetch(`/api/v1/service-checklists/${chk.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o item de checklist.');
    await loadChecklists();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir item de checklist.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadChecklists());
</script>

<template>
  <section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink to="/crm" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">CRM</RouterLink>
          <span aria-hidden="true">/</span>
          <span class="font-medium text-slate-900">Checklist de serviços</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Checklist para Execução de Serviços</h1>
        <p class="mt-1 text-sm text-slate-500">
          Gerencie os itens de inspeção técnica executados nas etapas de entrada e saída dos aparelhos na bancada.
        </p>
      </div>
      <button
        type="button"
        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        @click="startCreate"
      >
        Novo item de checklist
      </button>
    </div>

    <!-- Filtros rápidos -->
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="sm:col-span-2">
        <label for="chk-search" class="block text-xs font-medium text-slate-700">Buscar item</label>
        <input
          id="chk-search"
          v-model="search"
          type="search"
          placeholder="Buscar por nome, categoria ou descrição..."
          class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        />
      </div>
      <div>
        <label for="chk-stage-filter" class="block text-xs font-medium text-slate-700">Filtrar por etapa</label>
        <SearchableCombobox
          id="chk-stage-filter"
          v-model="stageFilter"
          :options="stageFilterOptions"
          class="mt-1"
        />
      </div>
      <div>
        <label for="chk-status-filter" class="block text-xs font-medium text-slate-700">Filtrar status</label>
        <SearchableCombobox
          id="chk-status-filter"
          v-model="statusFilter"
          :options="statusFilterOptions"
          class="mt-1"
        />
      </div>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhum item de checklist cadastrado.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button
            type="button"
            title="Editar"
            :aria-label="`Editar ${row.checklist.name}`"
            class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="startEdit(row.checklist)"
          >
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" />
            </svg>
          </button>
          <button
            type="button"
            title="Excluir"
            :disabled="deletingId === row.checklist.id"
            :aria-label="`Excluir ${row.checklist.name}`"
            class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40"
            @click="requestDelete(row.checklist)"
          >
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" />
            </svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <Modal
      v-model="modalOpen"
      :title="editingId ? 'Editar item de checklist' : 'Novo item de checklist'"
      description="Configure o item para verificação técnica na bancada de atendimento."
      @update:model-value="(isOpen) => !isOpen && closeModal()"
    >
      <form class="space-y-4" @submit.prevent="saveChecklist">
        <div>
          <label for="form-chk-name" class="block text-sm font-medium text-slate-700">Nome do item *</label>
          <input
            id="form-chk-name"
            v-model="form.name"
            type="text"
            required
            maxlength="255"
            placeholder="Ex: Touch Screen, Câmera Frontal, Conector Dock"
            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="form-chk-cat" class="block text-sm font-medium text-slate-700">Categoria / Agrupamento</label>
            <input
              id="form-chk-cat"
              v-model="form.category"
              type="text"
              maxlength="100"
              placeholder="Ex: Tela e Toque, Áudio, Câmeras"
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>

          <div>
            <label for="form-chk-stage" class="block text-sm font-medium text-slate-700">Etapa de execução *</label>
            <SearchableCombobox
              id="form-chk-stage"
              v-model="form.stage"
              :options="formStageOptions"
              required
              class="mt-1"
            />
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="form-chk-srv" class="block text-sm font-medium text-slate-700">Serviço específico</label>
            <SearchableCombobox
              id="form-chk-srv"
              v-model="form.service_id"
              :options="serviceOptions"
              class="mt-1"
            />
          </div>

          <div>
            <label for="form-chk-order" class="block text-sm font-medium text-slate-700">Ordem de exibição</label>
            <input
              id="form-chk-order"
              v-model.number="form.sort_order"
              type="number"
              min="0"
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        <div>
          <label for="form-chk-desc" class="block text-sm font-medium text-slate-700">Instruções de teste / Procedimento</label>
          <textarea
            id="form-chk-desc"
            v-model="form.description"
            rows="2"
            placeholder="Ex: Testar toque em todo o teclado e bordas para detectar áreas mortas"
            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
          ></textarea>
        </div>

        <div class="space-y-2 pt-1">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              v-model="form.is_required"
              type="checkbox"
              class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            />
            Item de verificação obrigatória para conclusão do serviço
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              v-model="form.active"
              type="checkbox"
              class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            />
            Item ativo
          </label>
        </div>

        <p v-if="formError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="closeModal"
          >
            Cancelar
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
          >
            {{ saving ? 'Salvando…' : 'Salvar' }}
          </button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog
      v-model="deleteConfirmationOpen"
      title="Excluir item de checklist"
      :message="`Deseja realmente excluir o item “${checklistPendingDeletion?.name ?? ''}”? Históricos de ordens de serviço já inspecionadas serão preservados.`"
      confirm-label="Excluir"
      :busy="deletingId !== null"
      @confirm="deleteChecklist"
    />
  </section>
</template>

