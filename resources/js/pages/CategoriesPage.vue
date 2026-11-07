<script setup lang="ts">
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import Modal from '../components/Modal.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

export type CategoryId = string;

interface Category {
  id: CategoryId;
  name: string;
  parent_id: CategoryId | null;
  children: Category[];
}

const categories = ref<Category[]>([]);
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const deletingId = ref<CategoryId | null>(null);
const categoryPendingDeletion = ref<Category | null>(null);
const deleteConfirmationOpen = ref(false);
const formError = ref('');
const editingId = ref<CategoryId | null>(null);
const modalOpen = ref(false);
const form = ref({ name: '', parent_id: '' });

async function loadCategories() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/categories', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Não foi possível carregar as categorias.');
    const payload = await response.json() as { data: Category[] };
    categories.value = payload.data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar categorias.';
  } finally {
    loading.value = false;
  }
}

function startCreate(parentId: CategoryId | null = null) {
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', parent_id: parentId ?? '' };
  modalOpen.value = true;
}

function startEdit(category: Category) {
  editingId.value = category.id;
  formError.value = '';
  form.value = { name: category.name, parent_id: category.parent_id ?? '' };
  modalOpen.value = true;
}

function closeCategoryModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  form.value = { name: '', parent_id: '' };
}

function flattenCategories(nodes: Category[], depth = 0): Array<{ category: Category; depth: number }> {
  return nodes.flatMap((category) => [
    { category, depth },
    ...flattenCategories(category.children ?? [], depth + 1),
  ]);
}

function descendantIds(category: Category): CategoryId[] {
  return (category.children ?? []).flatMap((child) => [child.id, ...descendantIds(child)]);
}

const flatCategories = computed(() => flattenCategories(categories.value));
const parentCategoryOptions = computed<ComboboxOption[]>(() => [{ value: '', label: 'Sem categoria superior' }, ...flatCategories.value.map(({ category, depth }) => ({ value: category.id, label: `${'— '.repeat(depth)}${category.name}`, disabled: unavailableParentIds.value.includes(category.id) }))]);
const gridColumns: DataGridColumn[] = [{ key: 'name', label: 'Categoria' }];
const gridRows = computed(() => flatCategories.value.map(({ category, depth }) => ({
  id: category.id,
  name: `${depth > 0 ? '└ '.repeat(depth) : ''}${category.name}`,
  category,
})));
const unavailableParentIds = computed(() => {
  if (editingId.value === null) return [];
  const edited = flatCategories.value.find(({ category }) => category.id === editingId.value)?.category;
  return edited ? [edited.id, ...descendantIds(edited)] : [];
});

async function saveCategory() {
  saving.value = true;
  formError.value = '';
  try {
    const response = await fetch(editingId.value === null ? '/api/v1/categories' : `/api/v1/categories/${editingId.value}`, {
      method: editingId.value === null ? 'POST' : 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ name: form.value.name, parent_id: form.value.parent_id || null }),
    });
    if (!response.ok) {
      const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
      throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Não foi possível salvar a categoria.');
    }
    closeCategoryModal();
    await loadCategories();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar categoria.';
  } finally {
    saving.value = false;
  }
}

async function requestDeleteCategory(category: Category) {
  if (category.children?.length) {
    error.value = 'Remova ou mova as subcategorias antes de excluir esta categoria.';
    return;
  }
  categoryPendingDeletion.value = category;
  deleteConfirmationOpen.value = true;
}

async function deleteCategory() {
  const category = categoryPendingDeletion.value;
  if (!category) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = category.id;
  error.value = '';
  try {
    const response = await fetch(`/api/v1/categories/${category.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) {
      const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
      throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Não foi possível excluir a categoria.');
    }
    if (editingId.value === category.id) closeCategoryModal();
    await loadCategories();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir categoria.';
  } finally {
    deletingId.value = null;
  }
}

onMounted(() => void loadCategories());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Categorias</h1>
        <p class="mt-2 text-slate-500">Organize categorias e subcategorias para classificar os produtos.</p>
      </div>
      <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate()">Nova categoria</button>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="gridColumns" :rows="gridRows" :loading="loading" empty-message="Nenhuma categoria cadastrada.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button type="button" title="Adicionar subcategoria" class="rounded-md p-2 text-indigo-700 transition-colors hover:bg-indigo-50 hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" :aria-label="`Adicionar subcategoria a ${row.category.name}`" @click="startCreate(row.category.id)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5" /></svg>
          </button>
          <button type="button" title="Editar" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" :aria-label="`Editar ${row.category.name}`" @click="startEdit(row.category)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
          </button>
          <button type="button" title="Excluir" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" :disabled="!!row.category.children?.length || deletingId === row.category.id" :aria-label="`Excluir ${row.category.name}`" @click="requestDeleteCategory(row.category)">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <Modal
      v-model="modalOpen"
      :title="editingId === null ? 'Nova categoria' : 'Editar categoria'"
      description="Organize categorias e subcategorias para classificar os produtos."
      @update:model-value="(isOpen) => !isOpen && closeCategoryModal()"
    >
      <form @submit.prevent="saveCategory">
        <label for="category-name" class="block text-sm font-medium text-slate-700">Nome</label>
        <input id="category-name" v-model="form.name" required maxlength="255" autofocus class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        <label for="category-parent" class="mt-4 block text-sm font-medium text-slate-700">Categoria superior</label>
        <SearchableCombobox id="category-parent" v-model="form.parent_id" :options="parentCategoryOptions" placeholder="Buscar categoria…" />
        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeCategoryModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog
      v-model="deleteConfirmationOpen"
      title="Excluir categoria"
      :message="`Excluir a categoria “${categoryPendingDeletion?.name ?? ''}”?`"
      confirm-label="Excluir"
      :busy="deletingId !== null"
      @confirm="deleteCategory"
    />
  </section>
</template>

