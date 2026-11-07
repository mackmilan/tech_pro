<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import DataGrid from '../components/DataGrid.vue';
import type { DataGridColumn } from '../components/DataGrid.vue';
import Modal from '../components/Modal.vue';

type AttributeType = 'texto' | 'numerico' | 'selecao_unica' | 'selecao_multipla' | 'booleano';
interface StockItemAttribute {
  product_attribute_id?: string;
  name: string;
  type?: AttributeType;
  options?: string[] | null;
  required?: boolean;
  differentiate_stock?: boolean;
  per_unit?: boolean;
  value: string | number | boolean | string[] | null;
}
interface StockLocationRef {
  id: string;
  name: string;
  code: string;
  type?: string;
}

interface StockUnit {
  id: string;
  condition_id: string | null;
  condition?: { name: string } | null;
  stock_location_id?: string | null;
  stock_location?: StockLocationRef | null;
  source?: string | null;
  unit_cost: string | number | null;
  attributes: Record<string, unknown>;
}
interface StockItem {
  id: string;
  quantity: number;
  units?: StockUnit[];
  product: { id?: string; name: string; sku: string } | null;
  stock: { id?: string; branch: { name: string; code: string } | null } | null;
  stock_location?: StockLocationRef | null;
  stock_locations?: StockLocationRef[];
  source?: string | null;
  sources?: string[];
  attributes?: StockItemAttribute[];
}

const items = ref<StockItem[]>([]);
const search = ref('');
const loading = ref(false);
const error = ref('');
const columns: DataGridColumn[] = [
  { key: 'product', label: 'Produto' },
  { key: 'sku', label: 'SKU' },
  { key: 'branch', label: 'Filial' },
  { key: 'quantity', label: 'Quantidade' },
];
const gridColumns = computed(() => [...columns, { key: 'attributes', label: 'Atributos', sortable: false, groupable: false }]);
interface StockRow {
  id: string;
  product: string;
  sku: string;
  branch: string;
  quantity: number;
  attributes: string;
  units: StockUnit[];
  item: StockItem;
}

function formatSingleValue(val: unknown): string {
  if (val === null || val === undefined || val === '') return '—';
  if (typeof val === 'boolean') return val ? 'Sim' : 'Não';
  if (Array.isArray(val)) {
    const formatted = val.map(formatSingleValue).filter((v) => v !== '—');
    return formatted.length ? formatted.join(', ') : '—';
  }
  return String(val).trim();
}

function formatSource(source: string | null | undefined): string {
  if (!source) return '—';
  if (source === 'fornecedor') return 'Fornecedor';
  if (source === 'aparelho') return 'Aparelho (entrada para compra)';
  return source;
}

function formatLocation(location: StockLocationRef | { name?: string; code?: string } | string | null | undefined): string {
  if (!location) return '—';
  if (typeof location === 'string') return location;
  if (location.name && location.code) return `${location.name} (${location.code})`;
  return location.name || location.code || '—';
}

function getUnitControlledValue(unit: StockUnit, attr: StockItemAttribute): string {
  const raw = (attr.product_attribute_id ? unit.attributes?.[attr.product_attribute_id] : null)
    ?? (attr.name ? unit.attributes?.[attr.name] : null)
    ?? (Array.isArray(attr.value) ? null : attr.value);
  return formatSingleValue(raw);
}

function getAttributeDistinctValues(attr: StockItemAttribute): string[] {
  let list: unknown[] = [];
  if (Array.isArray(attr.value)) {
    list = attr.value;
  } else if (attr.value !== null && attr.value !== undefined && attr.value !== '') {
    list = [attr.value];
  }
  const distinct = Array.from(new Set(list.map(formatSingleValue))).filter((v) => v !== '—');
  return distinct.length > 0 ? distinct : ['—'];
}

function splitItemIntoRows(item: StockItem): StockRow[] {
  const controlled = (item.attributes ?? []).filter((attr) => Boolean(attr.differentiate_stock));
  const productName = item.product?.name ?? 'Produto indisponível';
  const productSku = item.product?.sku ?? '—';
  const branchName = item.stock?.branch ? `${item.stock.branch.name} (${item.stock.branch.code})` : 'Filial indisponível';

  if (controlled.length === 0) {
    return [{
      id: item.id,
      product: productName,
      sku: productSku,
      branch: branchName,
      quantity: item.quantity,
      attributes: '—',
      units: item.units ?? [],
      item,
    }];
  }

  if (item.units && item.units.length > 0) {
    const groups = new Map<string, { units: StockUnit[]; values: Map<string, string> }>();
    for (const unit of item.units) {
      const values = new Map<string, string>();
      for (const attr of controlled) {
        values.set(attr.name, getUnitControlledValue(unit, attr));
      }
      const signature = controlled.map((attr) => `${attr.product_attribute_id || attr.name}:::${values.get(attr.name)}`).join('|||');
      if (!groups.has(signature)) {
        groups.set(signature, { units: [], values });
      }
      groups.get(signature)!.units.push(unit);
    }

    const rows: StockRow[] = [];
    let groupIndex = 0;
    for (const { units: groupUnits, values } of groups.values()) {
      const attributesText = controlled
        .map((attr) => `${attr.name}: ${values.get(attr.name) ?? '—'}`)
        .join('\n');
      const scopedItem: StockItem = {
        ...item,
        quantity: groupUnits.length,
        units: groupUnits,
        attributes: (item.attributes ?? []).map((attr) => {
          if (attr.differentiate_stock && values.has(attr.name)) {
            return { ...attr, value: values.get(attr.name) };
          }
          return attr;
        }),
      };
      rows.push({
        id: `${item.id}-${groupIndex++}`,
        product: productName,
        sku: productSku,
        branch: branchName,
        quantity: groupUnits.length,
        attributes: attributesText || '—',
        units: groupUnits,
        item: scopedItem,
      });
    }
    return rows;
  }

  let combinations: Array<Record<string, string>> = [{}];
  for (const attr of controlled) {
    const distinctVals = getAttributeDistinctValues(attr);
    const next: Array<Record<string, string>> = [];
    for (const combo of combinations) {
      for (const val of distinctVals) {
        next.push({ ...combo, [attr.name]: val });
      }
    }
    combinations = next;
  }

  const totalCombos = combinations.length;
  const baseQty = Math.floor(item.quantity / totalCombos);
  const remainder = item.quantity % totalCombos;

  return combinations.map((combo, idx) => {
    const qty = totalCombos === 1 ? item.quantity : Math.max(1, baseQty + (idx < remainder ? 1 : 0));
    const attributesText = controlled.map((attr) => `${attr.name}: ${combo[attr.name] ?? '—'}`).join('\n');
    const comboItem: StockItem = {
      ...item,
      quantity: qty,
      units: [],
      attributes: (item.attributes ?? []).map((attr) => {
        if (attr.differentiate_stock && combo[attr.name] !== undefined) {
          return { ...attr, value: combo[attr.name] };
        }
        return attr;
      }),
    };
    return {
      id: `${item.id}-${idx}`,
      product: productName,
      sku: productSku,
      branch: branchName,
      quantity: qty,
      attributes: attributesText || '—',
      units: [],
      item: comboItem,
    };
  });
}

const allRows = computed<StockRow[]>(() => items.value.flatMap(splitItemIntoRows));
const rows = computed<StockRow[]>(() => {
  const term = search.value.trim().toLocaleLowerCase();
  if (!term) return allRows.value;
  return allRows.value.filter((row) =>
    `${row.product} ${row.sku} ${row.branch} ${row.attributes}`.toLocaleLowerCase().includes(term)
  );
});
const totalQuantity = computed(() => rows.value.reduce((total, row) => total + row.quantity, 0));

const unitsModalOpen = ref(false);
const selectedItem = ref<StockItem | null>(null);

function openUnitsModal(item: StockItem) {
  selectedItem.value = item;
  unitsModalOpen.value = true;
}

function closeUnitsModal() {
  unitsModalOpen.value = false;
  selectedItem.value = null;
}

function unitAttributesList(unit: StockUnit, item: StockItem | null): [string, string][] {
  if (!unit.attributes) return [];
  const entries: [string, string][] = [];
  const attributeDefinitions = item?.attributes ?? [];

  for (const [key, val] of Object.entries(unit.attributes)) {
    if (val === null || val === undefined || val === '') continue;
    const def = attributeDefinitions.find((attr) => attr.product_attribute_id === key || attr.name === key);
    const label = def?.name ?? key;
    const displayVal = typeof val === 'boolean' ? (val ? 'Sim' : 'Não') : (Array.isArray(val) ? val.join(', ') : String(val));
    entries.push([label, displayVal]);
  }
  return entries;
}

async function responseError(response: Response): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Não foi possível carregar o estoque.');
  } catch {
    return new Error('Não foi possível carregar o estoque.');
  }
}

async function loadItems() {
  loading.value = true;
  error.value = '';
  try {
    const firstResponse = await fetch('/api/v1/stock-items?per_page=100', { headers: { Accept: 'application/json' } });
    if (!firstResponse.ok) throw await responseError(firstResponse);
    const firstPage = await firstResponse.json() as { data: StockItem[]; last_page?: number };
    const allItems = [...firstPage.data];
    const lastPage = Math.max(1, firstPage.last_page ?? 1);

    for (let page = 2; page <= lastPage; page += 1) {
      const response = await fetch(`/api/v1/stock-items?per_page=100&page=${page}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw await responseError(response);
      const payload = await response.json() as { data: StockItem[] };
      allItems.push(...payload.data);
    }

    items.value = allItems;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar o estoque.';
  } finally {
    loading.value = false;
  }
}

onMounted(() => void loadItems());
</script>

<template>
  <section>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
      <div>
        <RouterLink to="/erp" class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">← Voltar ao ERP</RouterLink>
        <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Operação</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight">Estoque</h1>
        <p class="mt-2 text-slate-500">Consulte os produtos e as quantidades disponíveis por filial.</p>
      </div>
      <RouterLink to="/erp/estoque/novo-lancamento" aria-label="Lançar entrada de estoque" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Lançar entrada</RouterLink>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
      <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Itens cadastrados</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ rows.length }}</p>
      </article>
      <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-slate-500">Unidades em estoque</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ totalQuantity }}</p>
      </article>
    </div>

    <label for="stock-search" class="mb-2 block text-sm font-medium text-slate-700">Buscar produto ou filial</label>
    <input id="stock-search" v-model="search" type="search" placeholder="Nome, SKU ou filial" class="mb-4 w-full max-w-md rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100">
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <DataGrid :columns="gridColumns" :rows="rows" :loading="loading" :show-actions="false" empty-message="Nenhum item de estoque cadastrado.">
      <template #cell-quantity="{ value, row }">
        <div class="flex items-center gap-2">
          <span>{{ value }}</span>
          <button
            v-if="row.units.length"
            type="button"
            class="rounded border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-indigo-600 shadow-sm transition-colors hover:bg-indigo-50 hover:text-indigo-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            :aria-label="`Ver itens individuais de ${row.product}`"
            @click="openUnitsModal(row.item)"
          >
            Ver itens
          </button>
        </div>
      </template>
      <template #cell-attributes="{ value }">
        <span class="whitespace-pre-line text-sm text-slate-700">{{ value }}</span>
      </template>
    </DataGrid>

    <Modal
      v-model="unitsModalOpen"
      :title="`Itens individuais — ${selectedItem?.product?.name ?? 'Produto'}`"
      :description="`Filial: ${selectedItem?.stock?.branch?.name ? `${selectedItem.stock.branch.name} (${selectedItem.stock.branch.code})` : '—'} · Total de unidades: ${selectedItem?.units?.length ?? selectedItem?.quantity ?? 0}`"
      @update:model-value="(isOpen) => !isOpen && closeUnitsModal()"
    >
      <div v-if="selectedItem?.units?.length" class="space-y-3">
        <article
          v-for="(unit, unitIndex) in selectedItem.units"
          :key="unit.id || unitIndex"
          class="rounded-lg border border-slate-200 bg-slate-50/50 p-4 text-sm"
        >
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-2">
            <span class="font-semibold text-slate-900">Unidade {{ unitIndex + 1 }}</span>
            <span v-if="unit.condition?.name" class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
              {{ unit.condition.name }}
            </span>
          </div>
          <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium text-slate-500">Custo unitário</dt>
              <dd class="mt-0.5 text-sm text-slate-800">
                {{ unit.unit_cost != null && unit.unit_cost !== '' ? (isNaN(Number(unit.unit_cost)) ? String(unit.unit_cost) : `R$ ${Number(unit.unit_cost).toFixed(2)}`) : '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-slate-500">Condição</dt>
              <dd class="mt-0.5 text-sm text-slate-800">{{ unit.condition?.name ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-slate-500">Origem</dt>
              <dd class="mt-0.5 text-sm text-slate-800">{{ formatSource(unit.source ?? selectedItem?.source) }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-slate-500">Localização no estoque</dt>
              <dd class="mt-0.5 text-sm text-slate-800">{{ formatLocation(unit.stock_location ?? selectedItem?.stock_location) }}</dd>
            </div>
            <div v-for="[attrName, attrVal] in unitAttributesList(unit, selectedItem)" :key="attrName">
              <dt class="text-xs font-medium text-slate-500">{{ attrName }}</dt>
              <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ attrVal }}</dd>
            </div>
          </dl>
        </article>
      </div>
      <div v-else class="rounded-lg border border-slate-200 bg-slate-50/50 p-4 text-sm">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium text-slate-500">Quantidade</dt>
            <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ selectedItem?.quantity ?? 0 }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-slate-500">Origem</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ formatSource(selectedItem?.source) }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-slate-500">Localização no estoque</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ formatLocation(selectedItem?.stock_location) }}</dd>
          </div>
        </dl>
      </div>

      <template #footer>
        <div class="flex justify-end">
          <button
            type="button"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            @click="closeUnitsModal"
          >
            Fechar
          </button>
        </div>
      </template>
    </Modal>

  </section>
</template>
