<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

type AttributeType = 'texto' | 'numerico' | 'selecao_unica' | 'selecao_multipla' | 'booleano';
interface Category { id: string; name: string; children: Category[] }
interface ProductAttribute {
  id: string;
  name: string;
  type: AttributeType;
  options: string[] | null;
  required: boolean;
  per_unit: boolean;
  differentiate_stock: boolean;
  active: boolean;
  entity_type: 'produto' | 'estoque';
  categories: Category[];
}

const types: Array<{ value: AttributeType; label: string }> = [
  { value: 'texto', label: 'Texto' },
  { value: 'numerico', label: 'Numérico' },
  { value: 'selecao_unica', label: 'Seleção única' },
  { value: 'selecao_multipla', label: 'Seleção múltipla' },
  { value: 'booleano', label: 'Sim/Não' },
];
const attributes = ref<ProductAttribute[]>([]);
const categories = ref<Category[]>([]);
const entityFilter = ref<'produto' | 'estoque'>('produto');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');
const modalOpen = ref(false);
const deleteConfirmationOpen = ref(false);
const pendingDeletion = ref<ProductAttribute | null>(null);
const editingId = ref<string | null>(null);
const deletingId = ref<string | null>(null);
const form = ref({ entity_type: 'produto' as 'produto' | 'estoque', name: '', type: 'texto' as AttributeType, optionsText: '', required: false, differentiate_stock: false, per_unit: false, active: true, category_ids: [] as string[] });
const columns: DataGridColumn[] = [
  { key: 'name', label: 'Atributo' },
  { key: 'type', label: 'Tipo' },
  { key: 'entity', label: 'Tipo de entidade', sortable: false, groupable: false },
  { key: 'categories', label: 'Categorias', sortable: false, groupable: false },
  { key: 'required', label: 'Obrigatório', sortable: false, groupable: false },
  { key: 'per_unit', label: 'Informar', sortable: false, groupable: false },
  { key: 'differentiate_stock', label: 'Diferencia estoque', sortable: false, groupable: false },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];
const rows = computed(() => attributes.value.map((attribute) => ({
  id: attribute.id,
  name: attribute.name,
  type: types.find(({ value }) => value === attribute.type)?.label ?? attribute.type,
  entity: attribute.entity_type === 'estoque' ? 'Estoque' : 'Produto',
  categories: attribute.categories.map(({ name }) => name).join(', ') || (attribute.entity_type === 'produto' ? 'Todas as categorias' : '—'),
  required: attribute.required ? 'Sim' : 'Não',
  per_unit: attribute.entity_type === 'estoque' ? (attribute.per_unit ? 'Por unidade' : 'Para todas as entradas') : '—',
  differentiate_stock: attribute.differentiate_stock ? 'Sim' : 'Não',
  status: attribute.active ? 'Ativo' : 'Inativo',
  attribute,
})));
const categoryOptions = computed(() => {
  const flatten = (nodes: Category[], depth = 0): Array<{ category: Category; depth: number }> => nodes.flatMap((category) => [
    { category, depth }, ...flatten(category.children ?? [], depth + 1),
  ]);
  return flatten(categories.value);
});
const isSelectionType = computed(() => form.value.type === 'selecao_unica' || form.value.type === 'selecao_multipla');
const entityOptions: ComboboxOption[] = [{ value: 'produto', label: 'Produto' }, { value: 'estoque', label: 'Estoque' }];
const attributeTypeOptions: ComboboxOption[] = types.map(({ value, label }) => ({ value, label }));
const entityFilterOptions = entityOptions;

async function readError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch { return new Error(fallback); }
}

async function loadData() {
  loading.value = true;
  error.value = '';
  try {
    const categoryResponse = await fetch('/api/v1/categories', { headers: { Accept: 'application/json' } });
    if (!categoryResponse.ok) throw await readError(categoryResponse, 'Não foi possível carregar as categorias.');
    const categoryPayload = await categoryResponse.json() as { data: Category[] };
    categories.value = categoryPayload.data;

    const attributeResponse = await fetch(`/api/v1/product-attributes?entity_type=${entityFilter.value}`, { headers: { Accept: 'application/json' } });
    if (!attributeResponse.ok) throw await readError(attributeResponse, 'Não foi possível carregar os atributos.');
    const attributePayload = await attributeResponse.json() as { data?: ProductAttribute[] | { data?: ProductAttribute[] } };
    const attributeData = Array.isArray(attributePayload.data) ? attributePayload.data : attributePayload.data?.data;
    if (!Array.isArray(attributeData)) throw new Error('A resposta de atributos possui um formato inválido.');
    attributes.value = attributeData;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar atributos.';
  } finally { loading.value = false; }
}

async function changeFormEntityType(value: 'produto' | 'estoque') {
  form.value.entity_type = value;
}
async function changeEntityFilter(value: 'produto' | 'estoque') {
  entityFilter.value = value;
  await loadData();
}

function resetForm() {
  form.value = { entity_type: entityFilter.value, name: '', type: 'texto', optionsText: '', required: false, differentiate_stock: false, per_unit: false, active: true, category_ids: [] };
}
function startCreate() {
  editingId.value = null;
  formError.value = '';
  resetForm();
  modalOpen.value = true;
}
function startEdit(attribute: ProductAttribute) {
  editingId.value = attribute.id;
  formError.value = '';
  form.value = {
    entity_type: attribute.entity_type,
    name: attribute.name,
    type: attribute.type,
    optionsText: attribute.options?.join('\n') ?? '',
    required: attribute.required,
    differentiate_stock: attribute.differentiate_stock,
    per_unit: attribute.per_unit,
    active: attribute.active,
    category_ids: attribute.categories.map(({ id }) => id),
  };
  modalOpen.value = true;
}
function closeModal() {
  modalOpen.value = false;
  editingId.value = null;
  formError.value = '';
  resetForm();
}
async function saveAttribute() {
  saving.value = true;
  formError.value = '';
  const categories = form.value.category_ids;
  const options = isSelectionType.value ? form.value.optionsText.split('\n').map((option) => option.trim()).filter(Boolean) : [];
  try {
    const response = await fetch(editingId.value ? `/api/v1/product-attributes/${editingId.value}` : '/api/v1/product-attributes', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
      credentials: 'same-origin',
      body: JSON.stringify({ name: form.value.name, entity_type: form.value.entity_type, type: form.value.type, options, required: form.value.required,
        differentiate_stock: form.value.differentiate_stock, per_unit: form.value.per_unit, category_ids: form.value.category_ids, active: form.value.active }),
    });
    if (!response.ok) throw await readError(response, 'Não foi possível salvar o atributo.');
    closeModal();
    await loadData();
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar atributo.';
  } finally { saving.value = false; }
}
function requestDelete(attribute: ProductAttribute) {
  pendingDeletion.value = attribute;
  deleteConfirmationOpen.value = true;
}
async function deleteAttribute() {
  const attribute = pendingDeletion.value;
  if (!attribute) return;
  deleteConfirmationOpen.value = false;
  deletingId.value = attribute.id;
  try {
    const response = await fetch(`/api/v1/product-attributes/${attribute.id}`, {
      method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, credentials: 'same-origin',
    });
    if (!response.ok) throw await readError(response, 'Não foi possível excluir o atributo.');
    await loadData();
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir atributo.';
  } finally { deletingId.value = null; }
}

onMounted(() => void loadData());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Atributos de produto e estoque</h1>
        <p class="mt-2 text-slate-500">Defina atributos conforme a entidade em que serão exibidos.</p>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div><label for="attribute-entity-filter" class="mr-2 text-sm font-medium text-slate-700">Tipo de Entidade</label><SearchableCombobox id="attribute-entity-filter" :model-value="entityFilter" :options="entityFilterOptions" placeholder="Buscar entidade…" @update:model-value="(value) => { if (value === 'produto' || value === 'estoque') void changeEntityFilter(value); }" /></div>
      <button type="button" aria-label="Novo atributo" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startCreate">Novo atributo</button>
    </div>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhum atributo cadastrado.">
      <template #actions="{ row }">
        <div class="flex justify-end gap-2 whitespace-nowrap">
          <button type="button" title="Editar" :aria-label="`Editar ${row.attribute.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="startEdit(row.attribute)">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg>
          </button>
          <button type="button" title="Excluir" :disabled="deletingId === row.attribute.id" :aria-label="`Excluir ${row.attribute.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.attribute)">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
          </button>
        </div>
      </template>
    </DataGrid>

    <Modal v-model="modalOpen" :title="editingId ? 'Editar atributo' : 'Novo atributo'" description="Configure a entidade e o tipo de valor em que o atributo será utilizado." @update:model-value="(isOpen) => !isOpen && closeModal()">
      <form @submit.prevent="saveAttribute">
        <label for="attribute-entity-type" class="mt-4 block text-sm font-medium text-slate-700">Tipo de Entidade</label>
        <SearchableCombobox id="attribute-entity-type" :model-value="form.entity_type" :options="entityOptions" placeholder="Buscar entidade…" @update:model-value="(value) => { if (value === 'produto' || value === 'estoque') void changeFormEntityType(value); }" />

        <label for="attribute-name" class="mt-4 block text-sm font-medium text-slate-700">Nome</label>
        <input id="attribute-name" v-model="form.name" required maxlength="255" autofocus class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">

        <label for="attribute-type" class="mt-4 block text-sm font-medium text-slate-700">Tipo</label>
        <SearchableCombobox id="attribute-type" v-model="form.type" :options="attributeTypeOptions" placeholder="Buscar tipo…" />

        <div v-if="isSelectionType" class="mt-4">
          <label for="attribute-options" class="block text-sm font-medium text-slate-700">Opções disponíveis</label>
          <textarea id="attribute-options" v-model="form.optionsText" required rows="4" placeholder="Uma opção por linha" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></textarea>
          <p class="mt-1 text-xs text-slate-500">Informe uma opção por linha.</p>
        </div>

        <fieldset class="mt-4">
          <legend class="text-sm font-medium text-slate-700">Categorias</legend>
          <p class="mt-1 text-xs text-slate-500">Sem seleção, o atributo fica disponível para todas as categorias.</p>
          <div v-if="categoryOptions.length" class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
            <label v-for="({ category, depth }) in categoryOptions" :key="category.id" :for="`category-${category.id}`" class="flex items-center gap-2 text-sm text-slate-700" :style="{ paddingLeft: `${depth * 16}px` }">
              <input :id="`category-${category.id}`" v-model="form.category_ids" type="checkbox" :value="category.id" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
              {{ category.name }}
            </label>
          </div>
          <p v-else class="mt-2 text-sm text-slate-500">Nenhuma categoria cadastrada.</p>
        </fieldset>

        <div class="mt-4 space-y-2">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="form.required" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"> Obrigatório
          </label>
          <label v-if="form.entity_type === 'estoque'" class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="form.per_unit" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"> Informar um valor para cada unidade recebida
          </label>
          <p v-if="form.entity_type === 'estoque' && !form.per_unit" class="ml-6 text-xs text-slate-500">O valor será compartilhado por todas as entradas e unidades deste lote.</p>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="form.differentiate_stock" type="checkbox" :disabled="form.per_unit" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"> Diferenciar estoque por este atributo
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"> Atributo ativo
          </label>
        </div>

        <p v-if="formError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" @click="closeModal">Cancelar</button>
          <button type="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </Modal>

    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir atributo" :message="`Excluir o atributo “${pendingDeletion?.name ?? ''}”?`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteAttribute" />
  </section>
</template>
