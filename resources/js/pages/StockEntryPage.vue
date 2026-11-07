<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

interface StockEntryAttribute {
  product_attribute_id: string;
  name: string;
  type: 'texto' | 'numerico' | 'selecao_unica' | 'selecao_multipla' | 'booleano';
  entity_type: 'produto' | 'estoque';
  differentiate_stock: boolean;
  per_unit: boolean;
  required: boolean;
}
interface Lookup { id: string; name: string; active: boolean }
interface Product extends Lookup { sku: string }
interface Branch { id: string; name: string; code: string; stock: { id: string } | null }
interface Location { id: string; stock_id: string; name: string; code: string }
interface UnitEntry { condition_id: string; unit_cost: number; attributes: Array<{ product_attribute_id: string; value: AttributeInput }> }

const router = useRouter();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const products = ref<Product[]>([]);
const branches = ref<Branch[]>([]);
const locations = ref<Location[]>([]);
const conditions = ref<Lookup[]>([]);
const attributes = ref<StockEntryAttribute[]>([]);
const attributeOptionsById = ref<Record<string, string[]>>({});
type AttributeInput = string | number | boolean | string[] | Array<string | number | boolean | null> | null;
const attributeValues = ref<Record<string, AttributeInput>>({});
const form = ref({ stock_id: '', stock_location_id: '', product_id: '', quantity: 1, condition_id: '', unit_cost: 0, source: 'fornecedor' });
const sourceOptions = ref<ComboboxOption[]>([
 { value: 'fornecedor', label: 'Fornecedor' },
 { value: 'aparelho', label: 'Aparelho (entrada para compra)' },
]);
const unitEntries = ref<UnitEntry[]>([]);
const stockOptions = ref<ComboboxOption[]>([]);
const locationOptions = ref<ComboboxOption[]>([]);
const productOptions = ref<ComboboxOption[]>([]);
const conditionOptions = ref<ComboboxOption[]>([]);
function setLookupOptions() {
  stockOptions.value = branches.value.filter((branch) => branch.stock).map((branch) => ({ value: branch.stock!.id, label: `${branch.name} (${branch.code})` }));
  locationOptions.value = locations.value.filter((location) => location.stock_id === form.value.stock_id).map((location) => ({ value: location.id, label: `${location.name} (${location.code})` }));
  productOptions.value = products.value.filter((product) => product.active).map((product) => ({ value: product.id, label: `${product.name} (${product.sku})` }));
  conditionOptions.value = conditions.value.map((condition) => ({ value: condition.id, label: condition.active ? condition.name : `${condition.name} (inativa)`, disabled: !condition.active }));
}
async function responseError(response: Response): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Não foi possível concluir o lançamento.');
  } catch {
    return new Error('Não foi possível concluir o lançamento.');
  }
}
async function fetchAll<T>(url: string): Promise<T[]> {
  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw await responseError(response);
  const payload = await response.json() as { data: T[]; last_page?: number };
  const result = [...payload.data];
  for (let page = 2; page <= (payload.last_page ?? 1); page += 1) {
    const separator = url.includes('?') ? '&' : '?';
    const pageResponse = await fetch(`${url}${separator}page=${page}`, { headers: { Accept: 'application/json' } });
    if (!pageResponse.ok) throw await responseError(pageResponse);
    result.push(...(await pageResponse.json() as { data: T[] }).data);
  }
  return result;
}
async function loadLookups() {
  loading.value = true;
  error.value = '';
  try {
    [products.value, branches.value, locations.value, conditions.value] = await Promise.all([
      fetchAll<Product>('/api/v1/products?per_page=100&active=1'),
      fetchAll<Branch>('/api/v1/branches?per_page=100'),
      fetchAll<Location>('/api/v1/stock-locations?per_page=100'),
      fetchAll<Lookup>('/api/v1/product-conditions?per_page=100'),
    ]);
    setLookupOptions();
    form.value.stock_id = stockOptions.value[0]?.value ?? '';
    locationOptions.value = locations.value.filter((location) => location.stock_id === form.value.stock_id).map((location) => ({ value: location.id, label: `${location.name} (${location.code})` }));
    form.value.stock_location_id = locationOptions.value[0]?.value ?? '';
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Não foi possível carregar os dados para a entrada.';
  } finally {
    loading.value = false;
  }
}
function changeStock(value: string | string[] | null) {
  form.value.stock_id = typeof value === 'string' ? value : '';
  form.value.stock_location_id = '';
  locationOptions.value = locations.value.filter((location) => location.stock_id === form.value.stock_id).map((location) => ({ value: location.id, label: `${location.name} (${location.code})` }));
}
async function changeProduct(value: string | string[] | null) {
  form.value.product_id = typeof value === 'string' ? value : '';
  attributes.value = [];
  attributeValues.value = {};
  if (!form.value.product_id) return;
  try {
    const response = await fetch(`/api/v1/products/${form.value.product_id}/stock-attributes`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw await responseError(response);
    const payload = await response.json() as { data: Array<StockEntryAttribute & { options: string[] | null }> };
    attributes.value = payload.data;
    attributeOptionsById.value = Object.fromEntries(payload.data.map((attribute) => [attribute.product_attribute_id, attribute.options ?? []]));
    attributeValues.value = Object.fromEntries(payload.data.map((attribute) => [
      attribute.product_attribute_id,
      attribute.per_unit || attribute.differentiate_stock ? Array.from({ length: form.value.quantity }, () => null) : attribute.type === 'selecao_multipla' ? [] : attribute.type === 'booleano' ? false : null,
    ]));
    const unitAttributes = payload.data.filter((attribute) => attribute.per_unit || attribute.differentiate_stock);
    unitEntries.value = Array.from({ length: form.value.quantity }, (_, index) => ({
      condition_id: '',
      unit_cost: form.value.unit_cost,
      attributes: unitAttributes.map((attribute) => ({
        product_attribute_id: attribute.product_attribute_id,
        value: (attributeValues.value[attribute.product_attribute_id] as Array<string | number | boolean | null>)[index] ?? null,
      })),
    }));
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Não foi possível carregar os atributos do produto.';
  }
}
const optionsForAttribute = (attribute: StockEntryAttribute): ComboboxOption[] => (attributeOptionsById.value[attribute.product_attribute_id] ?? []).map((option) => ({ value: option, label: option }));
function updateQuantity(value: number) {
  form.value.quantity = value;
  const next = Array.from({ length: value }, (_, index) => unitEntries.value[index] ?? {
    condition_id: form.value.condition_id, unit_cost: form.value.unit_cost, attributes: attributes.value.filter((attribute) => attribute.per_unit || attribute.differentiate_stock).map((attribute) => ({ product_attribute_id: attribute.product_attribute_id, value: null })),
  });
  unitEntries.value = next.map((entry) => ({
    ...entry,
    attributes: [
      ...entry.attributes.filter((item) => {
        const attribute = attributes.value.find((definition) => definition.product_attribute_id === item.product_attribute_id);
        return attribute?.per_unit || attribute?.differentiate_stock;
      }),
      ...attributes.value.filter((attribute) => attribute.per_unit || attribute.differentiate_stock).filter((attribute) => !entry.attributes.some((item) => item.product_attribute_id === attribute.product_attribute_id)).map((attribute) => ({ product_attribute_id: attribute.product_attribute_id, value: attribute.type === 'selecao_multipla' && !attribute.differentiate_stock ? [] : attribute.type === 'booleano' ? false : null })),
    ],
  }));
    const unitAttributes = attributes.value.filter((attribute) => attribute.per_unit || attribute.differentiate_stock);
    for (const attribute of unitAttributes) {
      const current = Array.isArray(attributeValues.value[attribute.product_attribute_id])
        ? [...attributeValues.value[attribute.product_attribute_id] as Array<string | number | boolean | null>]
        : [];
      attributeValues.value[attribute.product_attribute_id] = [...current.slice(0, value), ...Array.from({ length: Math.max(0, value - current.length) }, () => null)];
    }
}
function updateUnitAttribute(entry: UnitEntry, attribute: StockEntryAttribute, value: AttributeInput) {
  const normalizedValue = attribute.differentiate_stock && attribute.type === 'selecao_multipla'
    ? (Array.isArray(value) ? value[0] ?? null : value)
    : value;
  const existing = entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id);
  if (existing) existing.value = normalizedValue;
  else entry.attributes.push({ product_attribute_id: attribute.product_attribute_id, value: normalizedValue });
  const values = unitEntries.value.map((unit) => unit.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value ?? null);
  attributeValues.value[attribute.product_attribute_id] = values;
}
function updateUnitCondition(entry: UnitEntry, value: string | string[] | null) {
  entry.condition_id = typeof value === 'string' ? value : '';
}
function updateUnitCost(entry: UnitEntry, value: number) {
  entry.unit_cost = value;
}
function updatePerUnitValue(attribute: StockEntryAttribute, index: number, value: string | number | boolean | null) {
  const current = [...(attributeValues.value[attribute.product_attribute_id] as Array<string | number | boolean | null>)];
  current[index] = value;
  attributeValues.value[attribute.product_attribute_id] = current;
}
function updateAttribute(attribute: StockEntryAttribute, value: string | string[] | null) {
  if (attribute.differentiate_stock) {
    attributeValues.value[attribute.product_attribute_id] = typeof value === 'string' ? value : Array.isArray(value) ? value[0] ?? null : null;
    return;
  }
  attributeValues.value[attribute.product_attribute_id] = attribute.type === 'selecao_multipla' ? (Array.isArray(value) ? value : []) : (typeof value === 'string' ? value : null);
}
async function save() {
  saving.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/v1/stock-movements', {
      method: 'POST', credentials: 'same-origin',
      headers: {
        Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        ...form.value,
        type: 'in',
        attributes: attributes.value.filter((attribute) => !attribute.per_unit && !attribute.differentiate_stock).map((attribute) => ({
          product_attribute_id: attribute.product_attribute_id,
          value: attributeValues.value[attribute.product_attribute_id] ?? null,
        })),
    units: unitEntries.value.map((entry) => ({
      condition_id: entry.condition_id || form.value.condition_id || null,
      unit_cost: entry.unit_cost || form.value.unit_cost,
      attributes: entry.attributes.filter((item) => {
        const attribute = attributes.value.find((definition) => definition.product_attribute_id === item.product_attribute_id);
        return attribute?.per_unit || attribute?.differentiate_stock;
      }),
    })),
      }),
    });
    if (!response.ok) throw await responseError(response);
    await router.push('/erp/estoque');
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Não foi possível lançar a entrada de estoque.';
  } finally {
    saving.value = false;
  }
}
onMounted(() => void loadLookups());
</script>

<template>
  <section class="w-full">
    <div class="mb-8">
      <RouterLink to="/erp/estoque" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao estoque</RouterLink>
      <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Operação</p>
      <h1 class="mt-1 text-3xl font-bold tracking-tight">Lançar entrada de estoque</h1>
      <p class="mt-2 text-slate-500">Registre os dados do item e os atributos configurados para o produto.</p>
    </div>

    <form aria-label="Lançamento de entrada" class="space-y-6" @submit.prevent="save">
      <fieldset :disabled="loading || saving" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <legend class="sr-only">Dados da entrada</legend>
        <p v-if="loading" role="status" class="text-sm text-slate-500">Carregando dados…</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="stock-entry-stock" class="block text-sm font-medium text-slate-700">Filial</label>
            <SearchableCombobox id="stock-entry-stock" :model-value="form.stock_id" :options="stockOptions" placeholder="Buscar filial…" required @update:model-value="changeStock" />
          </div>
          <div>
            <label for="stock-entry-location" class="block text-sm font-medium text-slate-700">Localização</label>
            <SearchableCombobox id="stock-entry-location" v-model="form.stock_location_id" :options="locationOptions" placeholder="Buscar localização…" required :disabled="!form.stock_id" />
          </div>
          <div class="sm:col-span-2">
            <label for="stock-entry-product" class="block text-sm font-medium text-slate-700">Produto</label>
            <SearchableCombobox id="stock-entry-product" :model-value="form.product_id" :options="productOptions" placeholder="Buscar produto…" required @update:model-value="changeProduct" />
          </div>
          <div>
            <label for="stock-entry-quantity" class="block text-sm font-medium text-slate-700">Quantidade</label>
            <input id="stock-entry-quantity" :value="form.quantity" type="number" min="1" step="1" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" @input="updateQuantity(Number(($event.target as HTMLInputElement).value))">
          </div>
          <div>
            <label for="stock-entry-source" class="block text-sm font-medium text-slate-700">Origem</label>
            <SearchableCombobox id="stock-entry-source" v-model="form.source" :options="sourceOptions" placeholder="Selecionar origem…" required />
          </div>
          </div>

        <section aria-labelledby="stock-entry-units-heading" class="border-t border-slate-200 pt-5">
          <h2 id="stock-entry-units-heading" class="mb-4 text-lg font-semibold text-slate-900">Itens desta entrada</h2>
          <p class="mb-4 text-sm text-slate-500">Informe condição, custo e atributos por unidade.</p>
          <div v-for="(entry, unitIndex) in unitEntries" :key="unitIndex" class="mb-5 rounded-lg border border-slate-200 p-4">
            <h3 class="mb-3 text-sm font-semibold text-slate-800">Unidade {{ unitIndex + 1 }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label :for="`stock-entry-unit-condition-${unitIndex}`" class="block text-sm font-medium text-slate-700">Condição</label>
                <SearchableCombobox :id="`stock-entry-unit-condition-${unitIndex}`" :model-value="entry.condition_id" :options="conditionOptions" placeholder="Selecionar condição…" required @update:model-value="(value) => updateUnitCondition(entry, value)" />
              </div>
              <div>
                <label :for="`stock-entry-unit-cost-${unitIndex}`" class="block text-sm font-medium text-slate-700">Custo unitário</label>
                <input :id="`stock-entry-unit-cost-${unitIndex}`" :value="entry.unit_cost" type="number" min="0" step="0.01" required inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" @input="updateUnitCost(entry, Number(($event.target as HTMLInputElement).value))">
              </div>
              <div v-for="attribute in attributes" :key="attribute.product_attribute_id" class="min-w-0">
                <template v-if="attribute.per_unit || attribute.differentiate_stock">
                  <label :for="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" class="block text-sm font-medium text-slate-700">{{ attribute.name }}{{ attribute.required ? ' *' : '' }}{{ attribute.differentiate_stock ? ' (variação por unidade)' : '' }}</label>
                  <SearchableCombobox v-if="attribute.type === 'selecao_unica'" :id="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" :model-value="String(entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value ?? '') || null" :options="optionsForAttribute(attribute)" placeholder="Selecione…" :required="attribute.required" @update:model-value="(value) => updateUnitAttribute(entry, attribute, value)" />
                  <SearchableCombobox v-else-if="attribute.type === 'selecao_multipla'" :id="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" :input-test-id="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" :model-value="attribute.differentiate_stock ? (String(entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value ?? '') || null) : (entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value as string[]) ?? []" :options="optionsForAttribute(attribute)" placeholder="Selecione opções…" :multiple="!attribute.differentiate_stock" :required="attribute.required" @update:model-value="(value) => updateUnitAttribute(entry, attribute, value)" />
                  <input v-else-if="attribute.type === 'booleano'" :id="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" :checked="Boolean(entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value)" type="checkbox" class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @change="updateUnitAttribute(entry, attribute, ($event.target as HTMLInputElement).checked)">
                  <input v-else :id="`stock-entry-attribute-${attribute.product_attribute_id}-${unitIndex}`" :value="entry.attributes.find((item) => item.product_attribute_id === attribute.product_attribute_id)?.value ?? ''" :type="attribute.type === 'numerico' ? 'number' : 'text'" :required="attribute.required" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" @input="updateUnitAttribute(entry, attribute, attribute.type === 'numerico' ? (($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value)) : ($event.target as HTMLInputElement).value)">
                </template>
                <template v-else>
                  <label :for="`stock-entry-attribute-${attribute.product_attribute_id}`" class="block text-sm font-medium text-slate-700">{{ attribute.name }}{{ attribute.required ? ' *' : '' }} (compartilhado)</label>
                  <SearchableCombobox v-if="attribute.type === 'selecao_unica' || attribute.type === 'selecao_multipla'" :id="`stock-entry-attribute-${attribute.product_attribute_id}`" :input-test-id="`stock-entry-attribute-${attribute.product_attribute_id}`" :model-value="attributeValues[attribute.product_attribute_id] as string | string[] | null" :options="optionsForAttribute(attribute)" placeholder="Selecione…" :required="attribute.required || attribute.differentiate_stock" :multiple="attribute.type === 'selecao_multipla' && !attribute.differentiate_stock" @update:model-value="(value) => updateAttribute(attribute, value)" />
                  <input v-else-if="attribute.type === 'booleano'" :id="`stock-entry-attribute-${attribute.product_attribute_id}`" :checked="Boolean(attributeValues[attribute.product_attribute_id])" type="checkbox" class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @change="attributeValues[attribute.product_attribute_id] = ($event.target as HTMLInputElement).checked">
                  <input v-else :id="`stock-entry-attribute-${attribute.product_attribute_id}`" :value="attributeValues[attribute.product_attribute_id] ?? ''" :type="attribute.type === 'numerico' ? 'number' : 'text'" :required="attribute.required" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" @input="attributeValues[attribute.product_attribute_id] = attribute.type === 'numerico' ? (($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value)) : ($event.target as HTMLInputElement).value">
                </template>
              </div>
            </div>
          </div>
        </section>
      </fieldset>

      <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <RouterLink to="/erp/estoque" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Cancelar</RouterLink>
        <button type="submit" :disabled="loading || saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">{{ saving ? 'Lançando…' : 'Lançar entrada' }}</button>
      </div>
    </form>
  </section>
</template>
