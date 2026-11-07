<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

type AttributeType = 'texto' | 'numerico' | 'selecao_unica' | 'selecao_multipla' | 'booleano';
interface ProductImage { id: string; url: string | null; path: string; is_primary: boolean; original_name?: string | null }
interface Product { id: string; sku: string; name: string; category: string; category_id: string | null; brand: string | null; brand_id: string | null; description: string | null; sale_price: string | number; active: boolean; images?: ProductImage[] }
interface Category { id: string; name: string; parent_id: string | null; children: Category[] }
interface Lookup { id: string; name: string; active: boolean }
interface Attribute { product_attribute_id: string; name: string; type: AttributeType; options: string[] | null; required: boolean }
const route = useRoute();
const router = useRouter();
const productId = computed(() => typeof route.params.id === 'string' ? route.params.id : null);
const editing = computed(() => productId.value !== null);
const categories = ref<Category[]>([]);
const brands = ref<Lookup[]>([]);
const attributes = ref<Attribute[]>([]);
const attributeValues = ref<Record<string, unknown>>({});
const productImages = ref<ProductImage[]>([]);
const selectedImageFiles = ref<File[]>([]);
const imagePreviews = ref<Array<{ file: File; url: string }>>([]);
const uploadingImages = ref(false);
const imageError = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');
const form = ref({ sku: '', name: '', category: '', category_id: '', brand: '', brand_id: '', description: '', sale_price: 0, active: true });
function flattenCategories(nodes: Category[], depth = 0): Array<{ category: Category; depth: number }> { return nodes.flatMap((category) => [{ category, depth }, ...flattenCategories(category.children ?? [], depth + 1)]); }
const categoryOptions = computed(() => flattenCategories(categories.value));
const categoryComboboxOptions = computed<ComboboxOption[]>(() => categoryOptions.value.map(({ category, depth }) => ({ value: category.id, label: `${'— '.repeat(depth)}${category.name}` })));
const brandComboboxOptions = computed<ComboboxOption[]>(() => [{ value: '', label: 'Sem marca' }, ...brands.value.map((brand) => ({ value: brand.id, label: brand.active ? brand.name : `${brand.name} (inativa)`, disabled: !brand.active }))]);
async function responseError(response: Response, fallback: string): Promise<Error> {
  try { const payload = await response.json() as { message?: string; errors?: Record<string, string[]> }; return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback); }
  catch { return new Error(fallback); }
}
async function loadLookups() {
  const [categoryResponse, brandResponse] = await Promise.all([
    fetch('/api/v1/categories', { headers: { Accept: 'application/json' } }),
    fetch('/api/v1/brands?per_page=100', { headers: { Accept: 'application/json' } }),
  ]);
  if (!categoryResponse.ok) throw await responseError(categoryResponse, 'Não foi possível carregar as categorias.');
  if (!brandResponse.ok) throw await responseError(brandResponse, 'Não foi possível carregar as marcas.');
  categories.value = (await categoryResponse.json() as { data: Category[] }).data;
  brands.value = (await brandResponse.json() as { data: Lookup[] }).data;
}
async function loadAttributes(id: string | null) {
  const response = await fetch('/api/v1/product-attributes?entity_type=produto&all=true', { headers: { Accept: 'application/json' } });
  if (!response.ok) throw await responseError(response, 'Não foi possível carregar os atributos do produto.');
  const payload = await response.json() as { data: Array<Omit<Attribute, 'product_attribute_id'> & { id: string; categories: Array<{ id: string }> }> };
  const byId = new Map(categoryOptions.value.map(({ category }) => [category.id, category]));
  const applicable = new Set<string>();
  let current = form.value.category_id;
  while (current) { applicable.add(current); current = byId.get(current)?.parent_id ?? ''; }
  attributes.value = payload.data.filter((attribute) => attribute.active !== false && (!attribute.categories.length || attribute.categories.some(({ id: categoryId }) => applicable.has(categoryId))))
    .map((attribute) => ({ product_attribute_id: attribute.id, name: attribute.name, type: attribute.type, options: attribute.options, required: attribute.required }));
  attributeValues.value = Object.fromEntries(attributes.value.map((attribute) => [attribute.product_attribute_id, null]));
  if (id) {
    const valuesResponse = await fetch(`/api/v1/products/${id}/attributes`, { headers: { Accept: 'application/json' } });
    if (!valuesResponse.ok) throw await responseError(valuesResponse, 'Não foi possível carregar os valores dos atributos.');
    const saved = (await valuesResponse.json() as { data: Array<{ product_attribute_id: string; value: unknown }> }).data;
    const valuesById = new Map(saved.map((attribute) => [attribute.product_attribute_id, attribute.value]));
    attributeValues.value = Object.fromEntries(attributes.value.map((attribute) => [attribute.product_attribute_id, valuesById.get(attribute.product_attribute_id) ?? null]));
  }
}
function onCategoryChange(value: string | string[] | null) {
  form.value.category = typeof value === 'string' ? categoryOptions.value.find(({ category }) => category.id === value)?.category.name ?? '' : '';
  void loadAttributes(productId.value).catch((exception) => { formError.value = exception instanceof Error ? exception.message : 'Erro ao carregar atributos.'; });
}
const categoryAttributeOptions = (attribute: Attribute): ComboboxOption[] => (attribute.options ?? []).map((option) => ({ value: option, label: option }));
function isEmpty(value: unknown) { return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0); }
async function onFilesChange(event: Event) {
  const target = event.target as HTMLInputElement;
  const files = target.files ? Array.from(target.files) : [];
  if (!files.length) return;

  if (productId.value) {
    await uploadSelectedFiles(files);
  } else {
    for (const file of files) {
      selectedImageFiles.value.push(file);
      imagePreviews.value.push({ file, url: URL.createObjectURL(file) });
    }
  }
  target.value = '';
}

async function uploadSelectedFiles(files: File[]) {
  if (!productId.value) return;
  uploadingImages.value = true;
  imageError.value = '';
  try {
    for (const file of files) {
      const formData = new FormData();
      formData.append('image', file);
      const response = await fetch(`/api/v1/products/${productId.value}/images`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
        body: formData,
      });
      if (!response.ok) throw await responseError(response, 'Erro ao enviar imagem do produto.');
      const newImage = await response.json() as ProductImage;
      productImages.value.push(newImage);
    }
  } catch (exception) {
    imageError.value = exception instanceof Error ? exception.message : 'Erro ao enviar imagem.';
  } finally {
    uploadingImages.value = false;
  }
}

async function makePrimaryImage(image: ProductImage) {
  if (!productId.value) return;
  imageError.value = '';
  try {
    const response = await fetch(`/api/v1/products/${productId.value}/images/${image.id}/primary`, {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível definir como imagem principal.');
    productImages.value = productImages.value.map((img) => ({
      ...img,
      is_primary: img.id === image.id,
    }));
  } catch (exception) {
    imageError.value = exception instanceof Error ? exception.message : 'Erro ao alterar imagem principal.';
  }
}

async function removeImage(image: ProductImage) {
  if (!productId.value) return;
  imageError.value = '';
  try {
    const response = await fetch(`/api/v1/products/${productId.value}/images/${image.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível excluir a imagem.');
    productImages.value = productImages.value.filter((img) => img.id !== image.id);
    if (image.is_primary && productImages.value.length > 0) {
      productImages.value[0].is_primary = true;
    }
  } catch (exception) {
    imageError.value = exception instanceof Error ? exception.message : 'Erro ao excluir imagem.';
  }
}

function removeLocalPreview(index: number) {
  selectedImageFiles.value.splice(index, 1);
  imagePreviews.value.splice(index, 1);
}

async function loadForm() {
  loading.value = true; error.value = '';
  try {
    await loadLookups();
    if (productId.value) {
      const response = await fetch(`/api/v1/products/${productId.value}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw await responseError(response, 'Não foi possível carregar o produto.');
      const product = await response.json() as Product;
      form.value = { sku: product.sku, name: product.name, category: product.category, category_id: product.category_id ?? '', brand: product.brand ?? '', brand_id: product.brand_id ?? '', description: product.description ?? '', sale_price: Number(product.sale_price), active: product.active };
      productImages.value = product.images ?? [];
    }
    await loadAttributes(productId.value);
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Erro ao carregar os dados do produto.'; }
  finally { loading.value = false; }
}
async function saveProduct() {
  saving.value = true; formError.value = '';
  const missing = attributes.value.find((attribute) => attribute.required && isEmpty(attributeValues.value[attribute.product_attribute_id]));
  if (missing) { formError.value = `O atributo ${missing.name} é obrigatório.`; saving.value = false; return; }
  const category = categoryOptions.value.find(({ category: item }) => item.id === form.value.category_id)?.category;
  const payload = { ...form.value, category: category?.name ?? form.value.category, category_id: form.value.category_id || null, brand: brands.value.find((item) => item.id === form.value.brand_id)?.name ?? '', brand_id: form.value.brand_id || null };
  try {
    const response = await fetch(productId.value ? `/api/v1/products/${productId.value}` : '/api/v1/products', {
      method: productId.value ? 'PUT' : 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
      credentials: 'same-origin', body: JSON.stringify(payload),
    });
    if (!response.ok) throw await responseError(response, 'Não foi possível salvar o produto.');
    const saved = await response.json() as Product;
    if (attributes.value.length) {
      const valuesResponse = await fetch(`/api/v1/products/${saved.id}/attributes`, {
        method: 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
        credentials: 'same-origin', body: JSON.stringify({ attributes: attributes.value.map((attribute) => ({ product_attribute_id: attribute.product_attribute_id, value: attributeValues.value[attribute.product_attribute_id] ?? null })) }),
      });
      if (!valuesResponse.ok) throw await responseError(valuesResponse, 'Não foi possível salvar os atributos do produto.');
    }
    if (selectedImageFiles.value.length) {
      for (const file of selectedImageFiles.value) {
        const formData = new FormData();
        formData.append('image', file);
        await fetch(`/api/v1/products/${saved.id}/images`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          },
          credentials: 'same-origin',
          body: formData,
        });
      }
    }
    await router.push('/erp/produtos');
  } catch (exception) { formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao salvar produto.'; }
  finally { saving.value = false; }
}
onMounted(loadForm);
</script>

<template>
  <section>
    <RouterLink to="/erp/produtos" class="inline-flex rounded-md text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar aos produtos</RouterLink>
    <header class="mb-8 mt-4"><p class="text-sm font-medium text-indigo-600">ERP · Cadastros</p><h1 class="mt-1 text-3xl font-bold tracking-tight">{{ editing ? 'Editar produto' : 'Novo produto' }}</h1><p class="mt-2 text-slate-500">Informe os dados do produto e seus atributos específicos.</p></header>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <form v-if="!loading && !error" @submit.prevent="saveProduct" class="space-y-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <fieldset class="grid gap-4 border-b border-slate-200 pb-6 sm:grid-cols-2">
        <legend class="mb-4 w-full text-lg font-semibold text-slate-900">Identificação</legend>
        <label class="block text-sm font-medium text-slate-700">SKU<input id="product-sku" v-model="form.sku" required maxlength="80" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></label>
        <label class="block text-sm font-medium text-slate-700">Nome<input id="product-name" v-model="form.name" required maxlength="255" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></label>
        <div>
          <label for="product-category" class="block text-sm font-medium text-slate-700">Categoria</label>
          <SearchableCombobox id="product-category" v-model="form.category_id" :options="categoryComboboxOptions" placeholder="Buscar categoria…" :required="true" @update:model-value="onCategoryChange" />
        </div>
        <div>
          <label for="product-brand" class="block text-sm font-medium text-slate-700">Marca</label>
          <SearchableCombobox id="product-brand" v-model="form.brand_id" :options="brandComboboxOptions" placeholder="Buscar marca…" />
        </div>
      </fieldset>
      <fieldset class="grid gap-4 border-b border-slate-200 pb-6 sm:grid-cols-2">
        <legend class="mb-4 w-full text-lg font-semibold text-slate-900">Preços</legend>
        <label class="block text-sm font-medium text-slate-700">Preço de venda<input id="product-sale-price" v-model.number="form.sale_price" type="number" min="0" step="0.01" required inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></label>
      </fieldset>
      <div class="grid gap-5 sm:grid-cols-2">
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Descrição<textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"></textarea></label>
        <label class="flex items-center gap-2 text-sm font-medium text-slate-700"><input v-model="form.active" type="checkbox" class="rounded border border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Produto ativo</label>
      </div>
      <div class="border-b border-slate-200 pb-6">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Imagens do produto</h2>

        <p v-if="imageError" role="alert" class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ imageError }}</p>

        <!-- Galeria de imagens existentes (modo edição) -->
        <div v-if="productImages.length" class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
          <div
            v-for="img in productImages"
            :key="img.id"
            class="group relative flex flex-col items-center rounded-lg border border-slate-200 bg-slate-50 p-2 transition-all hover:border-indigo-300"
          >
            <div class="relative h-28 w-full overflow-hidden rounded-md bg-white">
              <img
                v-if="img.url"
                :src="img.url"
                :alt="img.original_name ?? 'Imagem do produto'"
                class="h-full w-full object-contain"
              />
              <div
                v-else
                class="flex h-full w-full items-center justify-center text-xs text-slate-400"
              >
                Sem imagem
              </div>
              <span
                v-if="img.is_primary"
                class="absolute left-1.5 top-1.5 rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white shadow-sm"
              >
                Principal
              </span>
            </div>
            <div class="mt-2 flex w-full items-center justify-between gap-1">
              <button
                v-if="!img.is_primary"
                type="button"
                class="text-[11px] font-medium text-indigo-600 hover:text-indigo-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded px-1"
                @click="makePrimaryImage(img)"
              >
                Definir principal
              </button>
              <span v-else class="text-[11px] font-medium text-slate-400">Principal</span>
              <button
                type="button"
                :aria-label="`Excluir imagem ${img.original_name ?? ''}`"
                class="text-[11px] font-medium text-red-600 hover:text-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 rounded px-1"
                @click="removeImage(img)"
              >
                Excluir
              </button>
            </div>
          </div>
        </div>

        <!-- Previews locais durante criação -->
        <div v-if="imagePreviews.length" class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
          <div
            v-for="(preview, idx) in imagePreviews"
            :key="idx"
            class="relative flex flex-col items-center rounded-lg border border-slate-200 bg-slate-50 p-2"
          >
            <div class="h-28 w-full overflow-hidden rounded-md bg-white">
              <img :src="preview.url" :alt="preview.file.name" class="h-full w-full object-contain" />
            </div>
            <div class="mt-2 flex w-full items-center justify-between">
              <span class="truncate text-[11px] text-slate-500">{{ preview.file.name }}</span>
              <button
                type="button"
                class="text-[11px] font-medium text-red-600 hover:text-red-800"
                @click="removeLocalPreview(idx)"
              >
                Remover
              </button>
            </div>
          </div>
        </div>

        <label for="product-image-upload" class="block text-sm font-medium text-slate-700">Adicionar fotos</label>
        <input
          id="product-image-upload"
          type="file"
          accept="image/*"
          multiple
          :disabled="uploadingImages"
          class="mt-2 block w-full text-xs text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-50"
          @change="onFilesChange"
        />
        <p class="mt-1 text-xs text-slate-500">Selecione uma ou mais fotos do produto (PNG, JPG, WEBP até 10MB).</p>
      </div>
      <fieldset v-if="attributes.length" class="grid gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-2"><legend class="px-1 text-sm font-semibold text-slate-700">Atributos do produto</legend>
        <div v-for="attribute in attributes" :key="attribute.product_attribute_id" class="block text-sm font-medium text-slate-700">
          <label :for="`product-attribute-${attribute.product_attribute_id}`">{{ attribute.name }}<span v-if="attribute.required" class="text-red-600"> *</span></label>
          <input v-if="attribute.type === 'texto'" :data-testid="`product-attribute-${attribute.product_attribute_id}`" v-model="attributeValues[attribute.product_attribute_id]" type="text" :required="attribute.required" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
          <input v-else-if="attribute.type === 'numerico'" :data-testid="`product-attribute-${attribute.product_attribute_id}`" v-model.number="attributeValues[attribute.product_attribute_id]" type="number" step="any" :required="attribute.required" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
          <SearchableCombobox v-else-if="attribute.type === 'selecao_unica'" :id="`product-attribute-${attribute.product_attribute_id}`" :input-test-id="`product-attribute-${attribute.product_attribute_id}`" :model-value="typeof attributeValues[attribute.product_attribute_id] === 'string' ? attributeValues[attribute.product_attribute_id] as string : ''" :options="categoryAttributeOptions(attribute)" placeholder="Selecione" :required="attribute.required" @update:model-value="(value) => { attributeValues[attribute.product_attribute_id] = typeof value === 'string' ? value : ''; }" />
          <SearchableCombobox v-else-if="attribute.type === 'selecao_multipla'" :id="`product-attribute-${attribute.product_attribute_id}`" :input-test-id="`product-attribute-${attribute.product_attribute_id}`" :model-value="Array.isArray(attributeValues[attribute.product_attribute_id]) ? attributeValues[attribute.product_attribute_id] as string[] : []" :options="categoryAttributeOptions(attribute)" placeholder="Selecionar opções…" multiple :required="attribute.required" @update:model-value="(value) => { attributeValues[attribute.product_attribute_id] = Array.isArray(value) ? value : []; }" />
          <input v-else :data-testid="`product-attribute-${attribute.product_attribute_id}`" :checked="Boolean(attributeValues[attribute.product_attribute_id])" @change="attributeValues[attribute.product_attribute_id] = ($event.target as HTMLInputElement).checked" type="checkbox" class="ml-2 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
        </div>
      </fieldset>
      <p v-if="formError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</p>
      <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4"><RouterLink to="/erp/produtos" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Cancelar</RouterLink><button type="submit" :disabled="saving || loading" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">{{ saving ? 'Salvando…' : 'Salvar produto' }}</button></div>
    </form>
    <p v-else-if="loading" role="status" aria-live="polite" class="text-sm text-slate-500">Carregando produto…</p>
  </section>
</template>
