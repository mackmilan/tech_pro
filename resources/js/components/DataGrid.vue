<script setup lang="ts">
import { computed, ref, watch } from 'vue';

export interface DataGridColumn {
  key: string;
  label: string;
  sortable?: boolean;
  groupable?: boolean;
}

const props = withDefaults(defineProps<{
  columns: DataGridColumn[];
  rows: Record<string, unknown>[];
  pageSize?: number;
  loading?: boolean;
  emptyMessage?: string;
  showActions?: boolean;
}>(), { pageSize: 10, loading: false, emptyMessage: 'Nenhum registro encontrado.', showActions: true });

const sortKey = ref<string | null>(null);
const sortDirection = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const groupKeys = ref<string[]>([]);

const sortedRows = computed(() => {
  if (!sortKey.value) return [...props.rows];
  const key = sortKey.value;
  const direction = sortDirection.value === 'asc' ? 1 : -1;
  return [...props.rows].sort((left, right) => {
    const a = left[key];
    const b = right[key];
    if (a == null && b == null) return 0;
    if (a == null) return -1 * direction;
    if (b == null) return 1 * direction;
    const compared = typeof a === 'number' && typeof b === 'number'
      ? a - b
      : String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    return compared * direction;
  });
});

const pageCount = computed(() => Math.max(1, Math.ceil(sortedRows.value.length / Math.max(1, props.pageSize))));
const visibleRows = computed(() => sortedRows.value.slice((page.value - 1) * props.pageSize, page.value * props.pageSize));
const visibleGroups = computed(() => {
  if (groupKeys.value.length === 0) return [];
  const key = groupKeys.value[0];
  const groups = new Map<string, { label: string; rows: Record<string, unknown>[] }>();
  for (const row of visibleRows.value) {
    const value = row[key];
    const label = value == null || value === '' ? '(Sem valor)' : String(value);
    const group = groups.get(label) ?? { label, rows: [] };
    group.rows.push(row);
    groups.set(label, group);
  }
  return [...groups.values()];
});
const groupedColumnNames = computed(() => groupKeys.value
  .map((key) => props.columns.find((column) => column.key === key)?.label)
  .filter((label): label is string => Boolean(label)));

watch(() => [props.rows.length, props.pageSize], () => { page.value = 1; });
watch(pageCount, (count) => { if (page.value > count) page.value = count; });

function sortBy(column: DataGridColumn) {
  if (column.sortable === false) return;
  if (sortKey.value === column.key) sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  else {
    sortKey.value = column.key;
    sortDirection.value = 'asc';
  }
  page.value = 1;
}

function allowDrop(event: DragEvent) {
  event.preventDefault();
}

function dropColumn(event: DragEvent) {
  event.preventDefault();
  const key = event.dataTransfer?.getData('text/plain');
  const column = props.columns.find((item) => item.key === key);
  if (column && column.groupable !== false && !groupKeys.value.includes(column.key)) {
    groupKeys.value = [...groupKeys.value, column.key];
    page.value = 1;
  }
}

function removeGroup(key: string) {
  groupKeys.value = groupKeys.value.filter((groupKey) => groupKey !== key);
  page.value = 1;
}
</script>

<template>
  <div data-testid="data-grid" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div
      data-testid="grouping-header"
      class="flex min-h-14 flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm"
      @dragover="allowDrop"
      @drop="dropColumn"
    >
      <span class="font-medium text-slate-600">Arraste uma coluna para agrupar</span>
      <span v-if="groupedColumnNames.length" class="text-slate-400">·</span>
      <span v-if="groupedColumnNames.length" class="text-slate-600">Agrupado por: {{ groupedColumnNames.join(', ') }}</span>
      <button
        v-for="key in groupKeys"
        :key="key"
        type="button"
        class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-medium text-indigo-800 transition-colors hover:bg-indigo-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        :aria-label="`Remover agrupamento ${columns.find((column) => column.key === key)?.label ?? key}`"
        @click="removeGroup(key)"
      >
        {{ columns.find((column) => column.key === key)?.label ?? key }} ×
      </button>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-3">
              <button
                v-if="column.sortable !== false"
                type="button"
                class="font-semibold transition-colors hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                :aria-label="`Ordenar por ${column.label}`"
                :aria-sort="sortKey === column.key ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"
                :draggable="column.groupable !== false"
                :data-column-key="column.key"
                @click="sortBy(column)"
                @dragstart="(event) => event.dataTransfer?.setData('text/plain', column.key)"
              >
                {{ column.label }}<span v-if="sortKey === column.key" aria-hidden="true">{{ sortDirection === 'asc' ? ' ↑' : ' ↓' }}</span>
              </button>
              <span v-else :draggable="column.groupable !== false" :data-column-key="column.key" @dragstart="(event) => event.dataTransfer?.setData('text/plain', column.key)">{{ column.label }}</span>
            </th>
            <th v-if="showActions" class="sticky right-0 z-[1] min-w-32 bg-slate-50 px-4 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td :colspan="columns.length + (showActions ? 1 : 0)" class="px-4 py-8 text-center text-slate-500">Carregando…</td>
          </tr>
          <tr v-else-if="visibleRows.length === 0">
            <td :colspan="columns.length + (showActions ? 1 : 0)" class="px-4 py-8 text-center text-slate-500">{{ emptyMessage }}</td>
          </tr>
          <template v-else-if="groupKeys.length">
            <template v-for="group in visibleGroups" :key="group.label">
              <tr class="bg-slate-50/80">
                <th :colspan="columns.length + 1" class="px-4 py-2 text-left font-semibold text-slate-700">{{ group.label }} ({{ group.rows.length }})</th>
              </tr>
              <tr v-for="(row, rowIndex) in group.rows" :key="String(row.id ?? `${group.label}-${rowIndex}`)">
                <td v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-3 text-slate-700">
                  <slot :name="`cell-${column.key}`" :value="row[column.key]" :row="row">{{ row[column.key] ?? '—' }}</slot>
                </td>
                <td v-if="showActions" class="sticky right-0 z-0 bg-white px-4 py-3 text-right"><slot name="actions" :row="row" /></td>
              </tr>
            </template>
          </template>
          <tr v-for="(row, rowIndex) in (groupKeys.length ? [] : visibleRows)" :key="String(row.id ?? rowIndex)">
            <td v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-3 text-slate-700">
              <slot :name="`cell-${column.key}`" :value="row[column.key]" :row="row">{{ row[column.key] ?? '—' }}</slot>
            </td>
            <td v-if="showActions" class="sticky right-0 z-0 bg-white px-4 py-3 text-right"><slot name="actions" :row="row" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="rows.length > 0" aria-label="Paginação" class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm">
      <span class="text-slate-500">Página {{ page }} de {{ pageCount }}</span>
      <div class="flex gap-2">
        <button type="button" aria-label="Página anterior" class="rounded-md border border-slate-300 px-3 py-1.5 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" :disabled="page <= 1" @click="page--">Anterior</button>
        <button v-for="pageNumber in pageCount" :key="pageNumber" type="button" :aria-label="`Página ${pageNumber}`" :aria-current="pageNumber === page ? 'page' : undefined" class="rounded-md border border-slate-300 px-3 py-1.5 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" :class="pageNumber === page ? 'bg-indigo-50 font-semibold text-indigo-700' : ''" @click="page = pageNumber">{{ pageNumber }}</button>
        <button type="button" aria-label="Próxima página" class="rounded-md border border-slate-300 px-3 py-1.5 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40" :disabled="page >= pageCount" @click="page++">Próxima</button>
      </div>
    </nav>
  </div>
</template>
