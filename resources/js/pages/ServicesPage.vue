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
  description: string | null;
  price: string | number;
  estimated_minutes: number | null;
  warranty_days: number | null;
  active: boolean;
}

const services = ref<Service[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const servicePendingDeletion = ref<Service | null>(null);
const editingId = ref<string | null>(null);

const search = ref('');
const statusFilter = ref('');

const statusFilterOptions: ComboboxOption[] = [
  { value: '', label: 'Todos os status' },
  { value: 'active', label: 'Somente ativos' },
  { value: 'inactive', label: 'Somente inativos' },
];

const form = ref({
  name: '',
  code: '',
  description: '',
  price: 0,
  estimated_minutes: '' as number | '',
  warranty_days: 90,
  active: true,
});

const columns: DataGridColumn[] = [
  { key: 'name', label: 'Serviço' },
  { key: 'code', label: 'Código' },
  { key: 'price', label: 'Preço' },
  { key: 'estimated_minutes', label: 'Tempo est.' },
  { key: 'warranty_days', label: 'Garantia' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];

function formatPrice(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

const filteredServices = computed(() => {
  return services.value.filter((service) => {
    if (statusFilter.value === 'active' && !service.active) return false;
    if (statusFilter.value === 'inactive' && service.active) return false;
    if (search.value.trim()) {
      const q = search.value.trim().toLowerCase();
      const matchName = service.name.toLowerCase().includes(q);
      const matchCode = service.code?.toLowerCase().includes(q) ?? false;
      const matchDesc = service.description?.toLowerCase().includes(q) ?? false;
      if (!matchName && !matchCode && !matchDesc) return false;
    }
    return true;
  });
});

const rows = computed(() => filteredServices.value.map((service) => ({
  id: service.id,
  name: service.name,
  code: service.code || '—',
  price: formatPrice(Number(service.price) || 0),
  estimated_minutes: service.estimated_minutes ? `${service.estimated_minutes} min` : '—',
  warranty_days: service.warranty_days ? `${service.warranty_days} dias` : '—',
  status: service.active ? 'Ativo' : 'Inativo',
  service,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadServices() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/services?per_page=200', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar os serviços.');
    const payload = await response.json() as { data: Service[] };
    services.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar serviços.';
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = {
    name: '',
    code: '',
    description: '',
    price: 0,
    estimated_minutes: '',
    warranty_days: 90,
    active: true,
  };
  modalOpen.value = true;
}

function startEdit(service: Service) {
  editingId.value = service.id;
  formError.value = '';
  form.value = {
    name: service.name,
    code: service.code ?? '',
    description: service.description ?? '',
    price: Number(service.price) || 0,
    estimated_minutes: service.estimated_minutes ?? '',
    warranty_days: service.warranty_days ?? 90,
    active: service.active,
  };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = {
    name: '',
    code: '',
    description: '',
    price: 0,
    estimated_minutes: '',
    warranty_days: 90,
    active: true,
  };
}

async function saveService() {
  saving.value = true;
  formError.value = '';

  const payload = {
    name: form.value.name.trim(),
    code: form.value.code.trim() || null,
    description: form.value.description.trim() || null,
    price: Number(form.value.price) || 0,
    estimated_minutes: form.value.estimated_minutes === '' ? null : Number(form.value.estimated_minutes),
    warranty_days: form.value.warranty_days === '' ? 0 : Number(form.value.warranty_days),
    active: form.value.active,
  };

  try {
    const url = editingId.value ? `/api/v1/services/${editingId.value}` : '/api/v1/services';
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

    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o serviço.');
    closeModal();
    await loadServices();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar serviço.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(service: Service) {
  servicePendingDeletion.value = service;
  deleteConfirmationOpen.value = true;
}

async function deleteService() {
  const service = servicePendingDeletion.value;
  if (!service) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = service.id;
  error.value = '';

  try {
    const response = await fetch(`/api/v1/services/${service.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o serviço.');
    await loadServices();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir serviço.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadServices());
</script>

<template>
  <section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <nav class="flex items-center gap-2 text-sm text-slate-500">
          <RouterLink to="/crm" class="transition-colors hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">CRM</RouterLink>
          <span aria-hidden="true">/</span>
          <span class="font-medium text-slate-900">Serviços</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Catálogo de Serviços</h1>
        <p class="mt-1 text-sm text-slate-500">
          Cadastre e gerencie a mão de obra técnica e serviços disponíveis para Ordens de Serviço.
        </p>
      </div>
      <button
        type="button"
        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        @click="startCreate"
      >
        Novo serviço
      </button>
    </div>

    <!-- Filtros rápidos -->
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="sm:col-span-2">
        <label for="service-search" class="block text-xs font-medium text-slate-700">Buscar serviço</label>
        <input
          id="service-search"
          v-model="search"
          type="search"
          placeholder="Buscar por nome, código ou descrição..."
          class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        />
      </div>
      <div>
        <label for="service-status-filter" class="block text-xs font-medium text-slate-700">Filtrar status</label>
        <SearchableCombobox
          id="service-status-filter"
          v-model="statusFilter"
          :options="statusFilterOptions"
          class="mt-1"
        />
      </div>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhum serviço cadastrado.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button
            type="button"
            title="Editar"
            :aria-label="`Editar ${row.service.name}`"
            class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="startEdit(row.service)"
          >
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" />
            </svg>
          </button>
          <button
            type="button"
            title="Excluir"
            :disabled="deletingId === row.service.id"
            :aria-label="`Excluir ${row.service.name}`"
            class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40"
            @click="requestDelete(row.service)"
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
      :title="editingId ? 'Editar serviço' : 'Novo serviço'"
      description="Informe os dados da mão de obra ou serviço técnico."
      @update:model-value="(isOpen) => !isOpen && closeModal()"
    >
      <form class="space-y-4" @submit.prevent="saveService">
        <div>
          <label for="srv-name" class="block text-sm font-medium text-slate-700">Nome do serviço *</label>
          <input
            id="srv-name"
            v-model="form.name"
            type="text"
            required
            maxlength="255"
            placeholder="Ex: Troca de conector dock USB-C"
            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="srv-code" class="block text-sm font-medium text-slate-700">Código / Referência</label>
            <input
              id="srv-code"
              v-model="form.code"
              type="text"
              maxlength="50"
              placeholder="Ex: SRV-CON-01"
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>

          <div>
            <label for="srv-price" class="block text-sm font-medium text-slate-700">Preço padrão (R$) *</label>
            <input
              id="srv-price"
              v-model.number="form.price"
              type="number"
              step="0.01"
              min="0"
              required
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="srv-minutes" class="block text-sm font-medium text-slate-700">Tempo estimado (minutos)</label>
            <input
              id="srv-minutes"
              v-model.number="form.estimated_minutes"
              type="number"
              min="1"
              placeholder="Ex: 45"
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>

          <div>
            <label for="srv-warranty" class="block text-sm font-medium text-slate-700">Garantia padrão (dias)</label>
            <input
              id="srv-warranty"
              v-model.number="form.warranty_days"
              type="number"
              min="0"
              placeholder="Ex: 90"
              class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        <div>
          <label for="srv-desc" class="block text-sm font-medium text-slate-700">Descrição / Procedimento técnico</label>
          <textarea
            id="srv-desc"
            v-model="form.description"
            rows="3"
            placeholder="Descreva detalhes ou instruções para execução do serviço..."
            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
          ></textarea>
        </div>

        <div>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              v-model="form.active"
              type="checkbox"
              class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            />
            Serviço ativo
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
      title="Excluir serviço"
      :message="`Deseja realmente excluir o serviço “${servicePendingDeletion?.name ?? ''}”? Serviços já vinculados a ordens de serviço não poderão ser excluídos.`"
      confirm-label="Excluir"
      :busy="deletingId !== null"
      @confirm="deleteService"
    />
  </section>
</template>

