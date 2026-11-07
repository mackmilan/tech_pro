<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';

interface ProductImage { id: string; url: string | null; is_primary: boolean }
interface Product { id: string; sku: string; name: string; category: string; category_id: string | null; brand: string | null; brand_id: string | null; sale_price: string | number; active: boolean; images?: ProductImage[]; category_relation?: { id: string; name: string } | null; brand_relation?: { id: string; name: string } | null }
interface Category { id: string; name: string; parent_id: string | null; children: Category[] }
interface Lookup { id: string; name: string; active: boolean }
interface Page { data: Product[]; current_page: number; last_page: number }
const products = ref<Product[]>([]);
const categories = ref<Category[]>([]);
const brands = ref<Lookup[]>([]);
const loading = ref(false);
const deletingId = ref<string | null>(null);
const error = ref('');
const deleteConfirmationOpen = ref(false);
const pendingDeletion = ref<Product | null>(null);
const search = ref('');
const categoryFilter = ref('');
const brandFilter = ref('');
const statusFilter = ref<'todos' | 'ativos' | 'inativos'>('todos');
const columns: DataGridColumn[] = [
  { key: 'sku', label: 'SKU' }, { key: 'name', label: 'Produto' }, { key: 'category', label: 'Categoria' },
  { key: 'brand', label: 'Marca' }, { key: 'sale_price', label: 'Preço de venda' },
  { key: 'status', label: 'Status', sortable: false, groupable: false },
];
const rows = computed(() => products.value.map((product) => ({
  id: product.id, sku: product.sku, name: product.name,
  category: product.category_relation?.name ?? product.category, brand: product.brand_relation?.name ?? product.brand ?? '—',
  sale_price: formatPrice(product.sale_price), status: product.active ? 'Ativo' : 'Inativo', product,
})));
function formatPrice(value: string | number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value)); }
function getProductThumbnail(product: Product): string | null {
  const primary = product.images?.find((img) => img.is_primary && img.url);
  return primary?.url ?? product.images?.[0]?.url ?? null;
}
function flattenCategories(nodes: Category[], depth = 0): Array<{ category: Category; depth: number }> {
  return nodes.flatMap((category) => [{ category, depth }, ...flattenCategories(category.children ?? [], depth + 1)]);
}
const categoryOptions = computed(() => flattenCategories(categories.value));
const categoryComboboxOptions = computed<ComboboxOption[]>(() => [{ value: '', label: 'Todas as categorias' }, ...categoryOptions.value.map(({ category, depth }) => ({ value: category.id, label: `${'— '.repeat(depth)}${category.name}` }))]);
const brandComboboxOptions = computed<ComboboxOption[]>(() => [{ value: '', label: 'Todas as marcas' }, ...brands.value.map((brand) => ({ value: brand.id, label: brand.name }))]);
const statusComboboxOptions: ComboboxOption[] = [{ value: 'todos', label: 'Todos os status' }, { value: 'ativos', label: 'Ativos' }, { value: 'inativos', label: 'Inativos' }];
async function responseError(response: Response, fallback: string): Promise<Error> {
  try { const payload = await response.json() as { message?: string; errors?: Record<string, string[]> }; return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback); }
  catch { return new Error(fallback); }
}
async function loadProducts() {
  loading.value = true; error.value = '';
  try {
    let page = 1; let lastPage = 1; const all: Product[] = [];
    const params = new URLSearchParams({ per_page: '100' });
    if (search.value.trim()) params.set('search', search.value.trim());
    if (categoryFilter.value) params.set('category_id', categoryFilter.value);
    if (brandFilter.value) params.set('brand_id', brandFilter.value);
    if (statusFilter.value !== 'todos') params.set('active', String(statusFilter.value === 'ativos'));
    do {
      params.set('page', String(page));
      const response = await fetch(`/api/v1/products?${params.toString()}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw await responseError(response, 'Não foi possível carregar os produtos.');
      const payload = await response.json() as Page; all.push(...payload.data); lastPage = payload.last_page; page += 1;
    } while (page <= lastPage);
    products.value = all;
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar produtos.'; }
  finally { loading.value = false; }
}
async function loadLookups() {
  const [categoryResponse, brandResponse] = await Promise.all([
    fetch('/api/v1/categories', { headers: { Accept: 'application/json' } }), fetch('/api/v1/brands?per_page=100', { headers: { Accept: 'application/json' } }),
  ]);
  if (!categoryResponse.ok) throw await responseError(categoryResponse, 'Não foi possível carregar as categorias.');
  if (!brandResponse.ok) throw await responseError(brandResponse, 'Não foi possível carregar as marcas.');
  categories.value = (await categoryResponse.json() as { data: Category[] }).data;
  brands.value = (await brandResponse.json() as { data: Lookup[] }).data;
}
function setStatusFilter(value: string | string[] | null) {
  const nextValue = value === 'ativos' || value === 'inativos' ? value : 'todos';
  if (nextValue !== statusFilter.value) { statusFilter.value = nextValue; }
}
function requestDelete(product: Product) { pendingDeletion.value = product; deleteConfirmationOpen.value = true; }

async function deleteProduct() {
  const product = pendingDeletion.value; if (!product) return;
  deleteConfirmationOpen.value = false; deletingId.value = product.id; error.value = '';
  try {
    const response = await fetch(`/api/v1/products/${product.id}`, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, credentials: 'same-origin' });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir o produto.');
    await loadProducts();
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao excluir produto.'; }
  finally { deletingId.value = null; }
}
onMounted(async () => { try { await Promise.all([loadProducts(), loadLookups()]); } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar os dados.'; } });
watch([search, categoryFilter, brandFilter, statusFilter], () => void loadProducts());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div><RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink><p class="mt-4 text-sm font-medium text-indigo-600">ERP · Cadastros</p><h1 class="mt-1 text-3xl font-bold tracking-tight">Produtos</h1><p class="mt-2 text-slate-500">Cadastre e mantenha produtos, preços e atributos específicos.</p></div>
      <RouterLink to="/erp/produtos/novo" aria-label="Novo produto" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Novo produto</RouterLink>
    </div>
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div><label for="products-search" class="block text-sm font-medium text-slate-700">Buscar</label><input id="products-search" v-model="search" type="search" placeholder="Nome ou SKU" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></div>
      <div><label for="products-category-filter" class="block text-sm font-medium text-slate-700">Categoria</label><SearchableCombobox id="products-category-filter" v-model="categoryFilter" :options="categoryComboboxOptions" placeholder="Buscar categoria…" /></div>
      <div><label for="products-brand-filter" class="block text-sm font-medium text-slate-700">Marca</label><SearchableCombobox id="products-brand-filter" v-model="brandFilter" :options="brandComboboxOptions" placeholder="Buscar marca…" /></div>
      <div><label for="products-status-filter" class="block text-sm font-medium text-slate-700">Status</label><SearchableCombobox id="products-status-filter" :model-value="statusFilter" :options="statusComboboxOptions" placeholder="Buscar status…" @update:model-value="setStatusFilter" /></div>
    </div>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="columns" :rows="rows" :loading="loading" empty-message="Nenhum produto cadastrado.">
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <img
            v-if="getProductThumbnail(row.product)"
            :src="getProductThumbnail(row.product)!"
            :alt="row.product.name"
            class="h-8 w-8 rounded-md border border-slate-200 object-cover bg-slate-50 shrink-0"
          />
          <div
            v-else
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-100 text-xs font-semibold text-slate-400"
          >
            <svg aria-hidden="true" class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
          </div>
          <span class="font-medium text-slate-900">{{ row.product.name }}</span>
        </div>
      </template>
      <template #actions="{ row }"><div class="flex justify-end gap-2 whitespace-nowrap">
        <RouterLink :to="`/erp/produtos/${row.product.id}/editar`" title="Editar" :aria-label="`Editar ${row.product.name}`" class="rounded-md p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M8 16l8.5-8.5a1.875 1.875 0 0 1 2.652 2.652L10.65 18.65 6 20l1.35-4.65Z" /></svg></RouterLink>
        <button type="button" title="Excluir" :disabled="deletingId === row.product.id" :aria-label="`Excluir ${row.product.name}`" class="rounded-md p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" @click="requestDelete(row.product)"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg></button>
      </div></template>
    </DataGrid>
    <ConfirmationDialog v-model="deleteConfirmationOpen" title="Excluir produto" :message="`Excluir o produto “${pendingDeletion?.name ?? ''}”?`" confirm-label="Excluir" :busy="deletingId !== null" @confirm="deleteProduct" />
  </section>
</template>
