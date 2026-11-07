<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

interface ProductCondition {
  id: string;
  name: string;
  active: boolean;
}

const conditions = ref<ProductCondition[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const conditionPendingDeletion = ref<ProductCondition | null>(null);
const editingId = ref<string | null>(null);
const form = ref({ name: '', active: true });
const columns: DataGridColumn[] = [
  { key: 'name', label: 'Condição' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];
const rows = computed(() => conditions.value.map((condition) => ({
  id: condition.id,
  name: condition.name,
  status: condition.active ? 'Ativa' : 'Inativa',
  condition,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadConditions() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/product-conditions', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar as condições.');
    const payload = await response.json() as { data: ProductCondition[] };
    conditions.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar condições.';
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', active: true };
  modalOpen.value = true;
}

function startEdit(condition: ProductCondition) {
  editingId.value = condition.id;
  formError.value = '';
  form.value = { name: condition.name, active: condition.active };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', active: true };
}

async function saveCondition() {
  saving.value = true;
  formError.value = '';
  try {
    const response = await fetch(editingId.value ? `/api/v1/product-conditions/${editingId.value}` : '/api/v1/product-conditions', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(form.value),
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a condição.');
    closeModal();
    await loadConditions();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar condição.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(condition: ProductCondition) {
  conditionPendingDeletion.value = condition;
  deleteConfirmationOpen.value = true;
}

async function deleteCondition() {
  const condition = conditionPendingDeletion.value;
  if (!condition) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = condition.id;
  error.value = '';
  try {
    const response = await fetch(`/api/v1/product-conditions/${condition.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir a condição.');
    await loadConditions();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir condição.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadConditions());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Condições de produto</h1>
        <p class="mt-2 text-slate-500">Cadastre condições como novo, usado ou recondicionado.</p>
      </div>
      <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate">Nova condição</button>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhuma condição cadastrada.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button type="button" title="Editar" :aria-label="`Editar ${row.condition.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startEdit(row.condition)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
          </button>
          <button type="button" title="Excluir" :disabled="deletingId === row.condition.id" :aria-label="`Excluir ${row.condition.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.condition)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <Modal v-model="modalOpen" :title="editingId ? 'Editar condição' : 'Nova condição'" description="Informe o nome e o status da condição." @update:model-value="(isOpen) => !isOpen && closeModal()">
      <form @submit.prevent="saveCondition">
        <label for="condition-name" class="block text-sm font-medium text-slate-700">Nome</label>
        <input id="condition-name" v-model="form.name" required maxlength="255" autofocus class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label class="mt-4 flex items-center gap-2 text-sm font-medium text-slate-700">
          <input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
          Condição ativa
        </label>
        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir condição" :message="`Excluir a condição “${conditionPendingDeletion?.name ?? ''}”? Condições vinculadas a produtos não podem ser excluídas.`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteCondition" />
  </section>
</template>
