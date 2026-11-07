<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

interface Supplier {
  id: string;
  name: string;
  document: string | null;
  email: string | null;
  phone: string | null;
}

const suppliers = ref<Supplier[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const supplierPendingDeletion = ref<Supplier | null>(null);
const editingId = ref<string | null>(null);
const form = ref({ name: '', document: '', email: '', phone: '' });
const columns: DataGridColumn[] = [
  { key: 'name', label: 'Fornecedor' },
  { key: 'document', label: 'Documento' },
  { key: 'email', label: 'E-mail' },
  { key: 'phone', label: 'Telefone' },
];
const rows = computed(() => suppliers.value.map((supplier) => ({
  id: supplier.id,
  name: supplier.name,
  document: supplier.document || '—',
  email: supplier.email || '—',
  phone: supplier.phone || '—',
  supplier,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadSuppliers() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/suppliers', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar os fornecedores.');
    const payload = await response.json() as { data: Supplier[] };
    suppliers.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar fornecedores.';
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', document: '', email: '', phone: '' };
  modalOpen.value = true;
}

function startEdit(supplier: Supplier) {
  editingId.value = supplier.id;
  formError.value = '';
  form.value = {
    name: supplier.name,
    document: supplier.document ?? '',
    email: supplier.email ?? '',
    phone: supplier.phone ?? '',
  };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', document: '', email: '', phone: '' };
}

async function saveSupplier() {
  saving.value = true;
  formError.value = '';
  try {
    const response = await fetch(editingId.value ? `/api/v1/suppliers/${editingId.value}` : '/api/v1/suppliers', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(form.value),
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o fornecedor.');
    closeModal();
    await loadSuppliers();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar fornecedor.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(supplier: Supplier) {
  supplierPendingDeletion.value = supplier;
  deleteConfirmationOpen.value = true;
}

async function deleteSupplier() {
  const supplier = supplierPendingDeletion.value;
  if (!supplier) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = supplier.id;
  error.value = '';
  try {
    const response = await fetch(`/api/v1/suppliers/${supplier.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o fornecedor.');
    await loadSuppliers();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir fornecedor.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadSuppliers());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Fornecedores</h1>
        <p class="mt-2 text-slate-500">Cadastre e mantenha seus fornecedores.</p>
      </div>
      <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate">Novo fornecedor</button>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhum fornecedor cadastrado.">
      <template #actions="{ row }"><div class="flex justify-end gap-2 whitespace-nowrap">
        <button type="button" title="Editar" :aria-label="`Editar ${row.supplier.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startEdit(row.supplier)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
        </button>
        <button type="button" title="Excluir" :disabled="deletingId === row.supplier.id" :aria-label="`Excluir ${row.supplier.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.supplier)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
        </button>
      </div></template>
    </DataGrid>

    <Modal v-model="modalOpen" :title="editingId ? 'Editar fornecedor' : 'Novo fornecedor'" description="Informe os dados do fornecedor." @update:model-value="(isOpen) => !isOpen && closeModal()">
      <form @submit.prevent="saveSupplier">
        <label for="supplier-name" class="block text-sm font-medium text-slate-700">Nome</label>
        <input id="supplier-name" v-model="form.name" required maxlength="255" autofocus class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="supplier-document" class="mt-4 block text-sm font-medium text-slate-700">CNPJ/CPF</label>
        <input id="supplier-document" v-model="form.document" maxlength="30" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="supplier-email" class="mt-4 block text-sm font-medium text-slate-700">E-mail</label>
        <input id="supplier-email" v-model="form.email" type="email" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="supplier-phone" class="mt-4 block text-sm font-medium text-slate-700">Telefone</label>
        <input id="supplier-phone" v-model="form.phone" maxlength="30" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir fornecedor" :message="`Excluir o fornecedor “${supplierPendingDeletion?.name ?? ''}”?`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteSupplier" />
  </section>
</template>
