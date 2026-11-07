<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

interface Stock {
  id: string;
  branch: { name: string; code: string } | null;
}

interface Branch {
  id: string;
  name: string;
  code: string;
  stock: Stock | null;
}

interface StockLocation {
  id: string;
  stock_id: string;
  name: string;
  code: string;
  type: string;
  stock?: Stock | null;
}

const locations = ref<StockLocation[]>([]);
const stocks = ref<Stock[]>([]);
const stockFilter = ref('');
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const locationPendingDeletion = ref<StockLocation | null>(null);
const editingId = ref<string | null>(null);
const form = ref({ stock_id: '', name: '', code: '', type: 'storage' });
const columns: DataGridColumn[] = [
  { key: 'name', label: 'Localização' },
  { key: 'code', label: 'Código' },
  { key: 'branch', label: 'Filial' },
  { key: 'type', label: 'Tipo' },
];
const filteredLocations = computed(() => stockFilter.value
  ? locations.value.filter((location) => location.stock_id === stockFilter.value)
  : locations.value);
const stockComboboxOptions = computed<ComboboxOption[]>(() => [{ value: '', label: 'Todas as filiais' }, ...stocks.value.map((stock) => ({ value: stock.id, label: `${stock.branch?.name ?? 'Filial indisponível'} (${stock.branch?.code ?? '—'})` }))]);
const stockFormOptions = computed<ComboboxOption[]>(() => stocks.value.map((stock) => ({ value: stock.id, label: `${stock.branch?.name ?? 'Filial indisponível'} (${stock.branch?.code ?? '—'})` })));
const locationTypeOptions: ComboboxOption[] = [{ value: 'storage', label: 'Depósito' }, { value: 'shelf', label: 'Prateleira' }, { value: 'aisle', label: 'Corredor' }, { value: 'position', label: 'Posição' }];
function setStockFilter(value: string | string[] | null) { stockFilter.value = typeof value === 'string' ? value : ''; }

const rows = computed(() => filteredLocations.value.map((location) => ({
  id: location.id,
  name: location.name,
  code: location.code,
  branch: location.stock?.branch ? `${location.stock.branch.name} (${location.stock.branch.code})` : 'Filial indisponível',
  type: location.type,
  location,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadPages<T>(url: string, fallback: string): Promise<T[]> {
  const firstResponse = await fetch(`${url}?per_page=100`, { headers: { Accept: 'application/json' } });
  if (!firstResponse.ok) throw await responseError(firstResponse, fallback);
  const firstPage = await firstResponse.json() as { data: T[]; last_page?: number };
  const results = [...firstPage.data];
  for (let page = 2; page <= (firstPage.last_page ?? 1); page += 1) {
    const response = await fetch(`${url}?per_page=100&page=${page}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response, fallback);
    const payload = await response.json() as { data: T[] };
    results.push(...payload.data);
  }
  return results;
}

async function loadData() {
  loading.value = true;
  error.value = '';
  try {
    const branches = await loadPages<Branch>('/api/v1/branches', 'Não foi possível carregar as filiais.');
    stocks.value = branches.flatMap((branch) => branch.stock
      ? [{ ...branch.stock, branch: { name: branch.name, code: branch.code } }]
      : []);
    locations.value = await loadPages<StockLocation>('/api/v1/stock-locations', 'Não foi possível carregar as localizações.');
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar localizações.';
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = { stock_id: stockFilter.value || stocks.value[0]?.id || '', name: '', code: '', type: 'storage' };
  modalOpen.value = true;
}

function startEdit(location: StockLocation) {
  editingId.value = location.id;
  formError.value = '';
  form.value = { stock_id: location.stock_id, name: location.name, code: location.code, type: location.type };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = { stock_id: '', name: '', code: '', type: 'storage' };
}

async function saveLocation() {
  saving.value = true;
  formError.value = '';
  try {
    const response = await fetch(editingId.value ? `/api/v1/stock-locations/${editingId.value}` : '/api/v1/stock-locations', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(form.value),
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a localização.');
    closeModal();
    await loadData();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar localização.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(location: StockLocation) {
  locationPendingDeletion.value = location;
  deleteConfirmationOpen.value = true;
}

async function deleteLocation() {
  const location = locationPendingDeletion.value;
  if (!location) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = location.id;
  error.value = '';
  try {
    const response = await fetch(`/api/v1/stock-locations/${location.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir a localização.');
    await loadData();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir localização.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadData());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Estoque</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Localizações de estoque</h1>
        <p class="mt-2 text-slate-500">Gerencie depósitos, prateleiras e demais localizações vinculadas ao estoque de cada filial.</p>
      </div>
      <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate">Nova localização</button>
    </div>

    <div class="mb-4 max-w-md"><label for="location-stock-filter" class="mb-2 block text-sm font-medium text-slate-700">Filtrar por filial</label><SearchableCombobox id="location-stock-filter" v-model="stockFilter" :options="stockComboboxOptions" placeholder="Buscar filial…" /></div>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhuma localização cadastrada.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button type="button" title="Editar" :aria-label="`Editar ${row.location.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startEdit(row.location)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
          </button>
          <button type="button" title="Excluir" :disabled="deletingId === row.location.id" :aria-label="`Excluir ${row.location.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.location)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <Modal v-model="modalOpen" :title="editingId ? 'Editar localização' : 'Nova localização'" description="Informe o estoque da filial e os dados da localização." @update:model-value="(isOpen) => !isOpen && closeModal()">
      <form @submit.prevent="saveLocation">
        <label for="location-stock" class="block text-sm font-medium text-slate-700">Estoque / filial</label>
        <SearchableCombobox id="location-stock" v-model="form.stock_id" :options="stockFormOptions" placeholder="Selecione uma filial…" required />
        <label for="location-name" class="mt-4 block text-sm font-medium text-slate-700">Nome</label>
        <input id="location-name" v-model="form.name" required maxlength="255" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="location-code" class="mt-4 block text-sm font-medium text-slate-700">Código</label>
        <input id="location-code" v-model="form.code" required maxlength="30" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="location-type" class="mt-4 block text-sm font-medium text-slate-700">Tipo</label>
        <SearchableCombobox id="location-type" v-model="form.type" :options="locationTypeOptions" placeholder="Buscar tipo…" required />
        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir localização" :message="`Excluir a localização “${locationPendingDeletion?.name ?? ''}”?`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteLocation" />
  </section>
</template>
