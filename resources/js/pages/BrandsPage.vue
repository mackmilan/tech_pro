<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

interface BrandImage {
  id: string;
  url: string | null;
  path: string;
  is_primary: boolean;
  original_name?: string | null;
}

interface Brand {
  id: string;
  name: string;
  active: boolean;
  images?: BrandImage[];
}

const brands = ref<Brand[]>([]);
const loading = ref(false);
const error = ref('');
const formError = ref('');
const saving = ref(false);
const deletingId = ref<string | null>(null);
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const brandPendingDeletion = ref<Brand | null>(null);
const editingId = ref<string | null>(null);
const form = ref({ name: '', active: true });
const selectedImageFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const currentBrandImage = ref<BrandImage | null>(null);
const columns: DataGridColumn[] = [
  { key: 'name', label: 'Marca' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];
const rows = computed(() => brands.value.map((brand) => ({
  id: brand.id,
  name: brand.name,
  status: brand.active ? 'Ativa' : 'Inativa',
  brand,
})));

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadBrands() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/brands', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response, 'Não foi possível carregar as marcas.');
    const payload = await response.json() as { data: Brand[] };
    brands.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar marcas.';
  } finally {
    loading.value = false;
  }
}

function onImageChange(event: Event) {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0] ?? null;
  if (!file) {
    selectedImageFile.value = null;
    imagePreview.value = null;
    return;
  }
  selectedImageFile.value = file;
  imagePreview.value = URL.createObjectURL(file);
}

async function removeCurrentImage() {
  if (!currentBrandImage.value || !editingId.value) return;
  try {
    const response = await fetch(`/api/v1/brands/${editingId.value}/images/${currentBrandImage.value.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível remover a imagem.');
    currentBrandImage.value = null;
    await loadBrands();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro ao remover imagem.';
  }
}

function startCreate() {
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', active: true };
  selectedImageFile.value = null;
  imagePreview.value = null;
  currentBrandImage.value = null;
  modalOpen.value = true;
}

function startEdit(brand: Brand) {
  editingId.value = brand.id;
  formError.value = '';
  form.value = { name: brand.name, active: brand.active };
  selectedImageFile.value = null;
  imagePreview.value = null;
  currentBrandImage.value = brand.images?.[0] ?? null;
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', active: true };
  selectedImageFile.value = null;
  imagePreview.value = null;
  currentBrandImage.value = null;
}

async function saveBrand() {
  saving.value = true;
  formError.value = '';
  try {
    const response = await fetch(editingId.value ? `/api/v1/brands/${editingId.value}` : '/api/v1/brands', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(form.value),
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível salvar a marca.');
    const saved = await response.json() as Brand;

    if (selectedImageFile.value) {
      const formData = new FormData();
      formData.append('image', selectedImageFile.value);
      formData.append('is_primary', 'true');

      const imageResponse = await fetch(`/api/v1/brands/${saved.id}/images`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
        body: formData,
      });
      if (!imageResponse.ok) throw await responseError(imageResponse, 'Marca salva, mas erro ao enviar a imagem.');
    }

    closeModal();
    await loadBrands();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar marca.';
  } finally {
    saving.value = false;
  }
}

function requestDelete(brand: Brand) {
  brandPendingDeletion.value = brand;
  deleteConfirmationOpen.value = true;
}

async function deleteBrand() {
  const brand = brandPendingDeletion.value;
  if (!brand) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = brand.id;
  error.value = '';
  try {
    const response = await fetch(`/api/v1/brands/${brand.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir a marca.');
    await loadBrands();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir marca.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadBrands());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Marcas</h1>
        <p class="mt-2 text-slate-500">Cadastre e mantenha as marcas dos produtos.</p>
      </div>
      <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate">Nova marca</button>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhuma marca cadastrada.">
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <img
            v-if="row.brand.images?.[0]?.url"
            :src="row.brand.images[0].url"
            :alt="row.brand.name"
            class="h-8 w-8 rounded-md border border-slate-200 object-contain bg-slate-50 p-0.5 shrink-0"
          />
          <div
            v-else
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-100 text-xs font-semibold text-slate-500 uppercase"
          >
            {{ row.brand.name.slice(0, 2) }}
          </div>
          <span class="font-medium text-slate-900">{{ row.brand.name }}</span>
        </div>
      </template>
      <template #actions="{ row }"><div class="flex justify-end gap-2 whitespace-nowrap">
        <button type="button" title="Editar" :aria-label="`Editar ${row.brand.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startEdit(row.brand)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
        </button>
        <button type="button" title="Excluir" :disabled="deletingId === row.brand.id" :aria-label="`Excluir ${row.brand.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.brand)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
        </button>
      </div></template>
    </DataGrid>

    <Modal v-model="modalOpen" :title="editingId ? 'Editar marca' : 'Nova marca'" description="Informe os dados da marca." @update:model-value="(isOpen) => !isOpen && closeModal()">
      <form @submit.prevent="saveBrand">
        <label for="brand-name" class="block text-sm font-medium text-slate-700">Nome</label>
        <input id="brand-name" v-model="form.name" required maxlength="255" autofocus class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">

        <div class="mt-4">
          <label for="brand-image" class="block text-sm font-medium text-slate-700">Logotipo / Imagem</label>
          <div v-if="imagePreview || currentBrandImage?.url" class="mt-2 flex items-center gap-3">
            <img
              :src="imagePreview || currentBrandImage!.url!"
              alt="Prévia do logotipo"
              class="h-14 w-14 rounded-lg border border-slate-200 object-contain bg-slate-50 p-1"
            />
            <button
              v-if="currentBrandImage && !imagePreview"
              type="button"
              class="text-xs font-semibold text-red-600 hover:text-red-800"
              @click="removeCurrentImage"
            >
              Remover imagem
            </button>
            <button
              v-if="imagePreview"
              type="button"
              class="text-xs font-semibold text-slate-600 hover:text-slate-800"
              @click="selectedImageFile = null; imagePreview = null"
            >
              Cancelar nova imagem
            </button>
          </div>
          <input
            id="brand-image"
            type="file"
            accept="image/*"
            class="mt-2 block w-full text-xs text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            @change="onImageChange"
          />
        </div>

        <label class="mt-4 flex items-center gap-2 text-sm font-medium text-slate-700">
          <input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
          Marca ativa
        </label>
        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir marca" :message="`Excluir a marca “${brandPendingDeletion?.name ?? ''}”? Marcas vinculadas a produtos não podem ser excluídas.`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteBrand" />
  </section>
</template>
