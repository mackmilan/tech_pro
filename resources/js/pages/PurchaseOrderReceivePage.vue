<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import SearchableCombobox from '../components/SearchableCombobox.vue';
import type { ComboboxOption } from '../components/SearchableCombobox.vue';

interface Branch {
  id: string;
  name: string;
  code: string;
  stock?: { id: string } | null;
}

interface Supplier {
  id: string;
  name: string;
  document: string | null;
}

interface Product {
  id: string;
  sku: string;
  name: string;
}

interface PurchaseOrderItem {
  id: string;
  product_id: string;
  quantity: number;
  unit_cost: string | number;
  total: string | number;
  notes: string | null;
  product?: Product | null;
}

interface PurchaseOrder {
  id: string;
  branch_id: string;
  supplier_id: string;
  number: string;
  status: 'draft' | 'ordered' | 'approved' | 'received' | 'cancelled';
  received_at: string | null;
  order_date: string;
  expected_delivery_date: string | null;
  subtotal: string | number;
  shipping_cost: string | number;
  discount: string | number;
  total: string | number;
  notes: string | null;
  branch?: Branch | null;
  supplier?: Supplier | null;
  items: PurchaseOrderItem[];
}

interface StockLocation {
  id: string;
  stock_id: string;
  name: string;
  code: string;
}

interface ProductCondition {
  id: string;
  name: string;
  active: boolean;
}

interface StockAttributeDefinition {
  product_attribute_id: string;
  name: string;
  entity_type: string;
  type: 'texto' | 'numerico' | 'selecao_unica' | 'selecao_multipla' | 'booleano';
  options: string[] | null;
  required: boolean;
  differentiate_stock: boolean;
  per_unit: boolean;
}

interface UnitFormState {
  attributes: Record<string, unknown>;
}

interface ItemReceiveFormState {
  purchase_order_item_id: string;
  stock_location_id: string;
  condition_id: string;
  notes: string;
  sharedAttributes: Record<string, unknown>;
  units: UnitFormState[];
}

const route = useRoute();
const router = useRouter();

const orderId = computed(() => String(route.params.id));
const order = ref<PurchaseOrder | null>(null);
const locations = ref<StockLocation[]>([]);
const conditions = ref<ProductCondition[]>([]);
const itemAttributes = ref<Record<string, StockAttributeDefinition[]>>({});

const loading = ref(true);
const receiving = ref(false);
const error = ref('');
const formError = ref('');

const globalLocationId = ref('');
const globalConditionId = ref('');
const globalNotes = ref('');

const itemsState = ref<ItemReceiveFormState[]>([]);

function formatPrice(value: string | number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value));
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const parts = value.split('-');
  if (parts.length === 3) {
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  }
  return value;
}

const locationOptions = computed<ComboboxOption[]>(() =>
  locations.value.map((loc) => ({ value: loc.id, label: `${loc.name} (${loc.code})` }))
);

const conditionOptions = computed<ComboboxOption[]>(() =>
  conditions.value.map((cond) => ({ value: cond.id, label: cond.name }))
);

function applyGlobalLocation(value: string | string[] | null) {
  const locId = typeof value === 'string' ? value : '';
  globalLocationId.value = locId;
  for (const item of itemsState.value) {
    item.stock_location_id = locId;
  }
}

function applyGlobalCondition(value: string | string[] | null) {
  const condId = typeof value === 'string' ? value : '';
  globalConditionId.value = condId;
  for (const item of itemsState.value) {
    item.condition_id = condId;
  }
}

function attributeOptions(attr: StockAttributeDefinition): ComboboxOption[] {
  return (attr.options ?? []).map((opt) => ({ value: opt, label: opt }));
}

async function responseError(response: Response, fallback: string): Promise<Error> {
  try {
    const payload = await response.json() as { message?: string; errors?: Record<string, string[]> };
    return new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback);
  } catch {
    return new Error(fallback);
  }
}

async function loadData() {
  loading.value = true;
  error.value = '';
  try {
    const [orderRes, conditionsRes] = await Promise.all([
      fetch(`/api/v1/purchase-orders/${orderId.value}`, { headers: { Accept: 'application/json' } }),
      fetch('/api/v1/product-conditions?per_page=100', { headers: { Accept: 'application/json' } }),
    ]);

    if (!orderRes.ok) throw await responseError(orderRes, 'Não foi possível carregar o pedido de compra.');
    if (!conditionsRes.ok) throw await responseError(conditionsRes, 'Não foi possível carregar as condições de produto.');

    const orderData = await orderRes.json() as PurchaseOrder;
    order.value = orderData;
    conditions.value = (await conditionsRes.json() as { data: ProductCondition[] }).data.filter((c) => c.active !== false);

    // Fetch locations for the branch's stock
    const stockId = orderData.branch?.stock?.id;
    const locationsUrl = stockId
      ? `/api/v1/stock-locations?stock_id=${stockId}&per_page=100`
      : '/api/v1/stock-locations?per_page=100';

    const locationsRes = await fetch(locationsUrl, { headers: { Accept: 'application/json' } });
    if (!locationsRes.ok) throw await responseError(locationsRes, 'Não foi possível carregar as localizações de estoque.');
    locations.value = (await locationsRes.json() as { data: StockLocation[] }).data;

    // Set default condition and location
    const defaultCondition = conditions.value[0]?.id ?? '';
    const defaultLocation = locations.value[0]?.id ?? '';
    globalConditionId.value = defaultCondition;
    globalLocationId.value = defaultLocation;

    // Load stock attribute definitions for each product in the order
    const productAttrMap: Record<string, StockAttributeDefinition[]> = {};
    for (const item of orderData.items) {
      if (!productAttrMap[item.product_id]) {
        const attrRes = await fetch(`/api/v1/products/${item.product_id}/stock-attributes`, { headers: { Accept: 'application/json' } });
        if (attrRes.ok) {
          const attrPayload = await attrRes.json() as { data: StockAttributeDefinition[] };
          productAttrMap[item.product_id] = attrPayload.data;
        } else {
          productAttrMap[item.product_id] = [];
        }
      }
    }
    itemAttributes.value = productAttrMap;

    // Initialize item receive state
    itemsState.value = orderData.items.map((item) => {
      const defs = productAttrMap[item.product_id] ?? [];
      const sharedDefs = defs.filter((d) => !d.per_unit && !d.differentiate_stock);
      const unitDefs = defs.filter((d) => d.per_unit || d.differentiate_stock);

      const sharedAttributes: Record<string, unknown> = {};
      for (const d of sharedDefs) {
        sharedAttributes[d.product_attribute_id] = d.type === 'selecao_multipla' ? [] : d.type === 'booleano' ? false : null;
      }

      const units: UnitFormState[] = Array.from({ length: item.quantity }, () => {
        const unitAttrs: Record<string, unknown> = {};
        for (const d of unitDefs) {
          unitAttrs[d.product_attribute_id] = d.type === 'selecao_multipla' ? [] : d.type === 'booleano' ? false : null;
        }
        return { attributes: unitAttrs };
      });

      return {
        purchase_order_item_id: item.id,
        stock_location_id: defaultLocation,
        condition_id: defaultCondition,
        notes: '',
        sharedAttributes,
        units,
      };
    });
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Erro inesperado ao carregar dados de recebimento.';
  } finally {
    loading.value = false;
  }
}

async function submitReceive() {
  formError.value = '';

  if (!order.value) return;

  // Validate items
  for (let i = 0; i < itemsState.value.length; i++) {
    const itemState = itemsState.value[i];
    const orderItem = order.value.items.find((item) => item.id === itemState.purchase_order_item_id);
    const productName = orderItem?.product?.name ?? `Item ${i + 1}`;

    if (!itemState.stock_location_id) {
      formError.value = `Selecione a localização de estoque para ${productName}.`;
      return;
    }

    if (!itemState.condition_id) {
      formError.value = `Selecione a condição para ${productName}.`;
      return;
    }

    const defs = itemAttributes.value[orderItem?.product_id ?? ''] ?? [];
    const sharedDefs = defs.filter((d) => !d.per_unit && !d.differentiate_stock);
    const unitDefs = defs.filter((d) => d.per_unit || d.differentiate_stock);

    // Validate required shared attributes
    for (const d of sharedDefs) {
      if (d.required) {
        const val = itemState.sharedAttributes[d.product_attribute_id];
        if (val === null || val === undefined || val === '' || (Array.isArray(val) && val.length === 0)) {
          formError.value = `O atributo "${d.name}" é obrigatório para ${productName}.`;
          return;
        }
      }
    }

    // Validate required per-unit attributes
    for (let u = 0; u < itemState.units.length; u++) {
      const unit = itemState.units[u];
      for (const d of unitDefs) {
        if (d.required || d.differentiate_stock) {
          const val = unit.attributes[d.product_attribute_id];
          if (val === null || val === undefined || val === '' || (Array.isArray(val) && val.length === 0)) {
            formError.value = `O atributo "${d.name}" da Unidade ${u + 1} é obrigatório para ${productName}.`;
            return;
          }
        }
      }
    }
  }

  receiving.value = true;

  const payload = {
    stock_location_id: globalLocationId.value || undefined,
    condition_id: globalConditionId.value || undefined,
    notes: globalNotes.value.trim() || undefined,
    items: itemsState.value.map((itemState) => {
      const sharedAttrs = Object.entries(itemState.sharedAttributes)
        .filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([attrId, v]) => ({ product_attribute_id: attrId, value: v }));

      const units = itemState.units.map((unit) => {
        const unitAttrs = Object.entries(unit.attributes)
          .filter(([, v]) => v !== null && v !== undefined && v !== '')
          .map(([attrId, v]) => ({ product_attribute_id: attrId, value: v }));
        return {
          stock_location_id: itemState.stock_location_id,
          condition_id: itemState.condition_id,
          attributes: unitAttrs,
        };
      });

      return {
        purchase_order_item_id: itemState.purchase_order_item_id,
        stock_location_id: itemState.stock_location_id,
        condition_id: itemState.condition_id,
        notes: itemState.notes.trim() || undefined,
        attributes: sharedAttrs,
        units,
      };
    }),
  };

  try {
    const response = await fetch(`/api/v1/purchase-orders/${orderId.value}/receive`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) throw await responseError(response, 'Não foi possível confirmar o recebimento do pedido.');
    await router.push('/erp/compras');
  } catch (exception) {
    formError.value = exception instanceof Error ? exception.message : 'Erro inesperado ao receber pedido.';
  } finally {
    receiving.value = false;
  }
}

onMounted(() => void loadData());
</script>

<template>
  <section class="w-full">
    <div class="mb-8">
      <RouterLink
        to="/erp/compras"
        class="text-sm font-medium text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
      >
        ← Voltar aos pedidos de compra
      </RouterLink>
      <p class="mt-4 text-sm font-medium text-indigo-600">ERP · Compras</p>
      <h1 class="mt-1 text-3xl font-bold tracking-tight">
        Receber pedido de compra {{ order?.number ?? '' }}
      </h1>
      <p class="mt-2 text-slate-500">
        Confirme o recebimento das mercadorias, informe o destino de estoque, condição física e os atributos de cada item/unidade.
      </p>
    </div>

    <div v-if="error" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ error }}
    </div>

    <div v-if="formError" role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ formError }}
    </div>

    <div v-if="loading" role="status" class="py-12 text-center text-sm text-slate-500">
      Carregando dados do pedido de compra…
    </div>

    <div v-else-if="order && order.status === 'received'" class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-900">
      <h2 class="text-lg font-bold">Pedido já recebido</h2>
      <p class="mt-1 text-sm">
        Este pedido de compra foi recebido em {{ formatDate(order.received_at ?? order.order_date) }}. Os itens já foram lançados no estoque.
      </p>
      <RouterLink
        to="/erp/compras"
        class="mt-4 inline-block rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
      >
        Voltar à lista de pedidos
      </RouterLink>
    </div>

    <div v-else-if="order && order.status === 'cancelled'" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-900">
      <h2 class="text-lg font-bold">Pedido cancelado</h2>
      <p class="mt-1 text-sm">
        Este pedido de compra foi cancelado e não pode ser recebido.
      </p>
      <RouterLink
        to="/erp/compras"
        class="mt-4 inline-block rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
      >
        Voltar à lista de pedidos
      </RouterLink>
    </div>

    <form v-else-if="order" class="space-y-8" @submit.prevent="submitReceive">
      <!-- Order Summary Card -->
      <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 space-y-4">
        <h2 class="text-base font-semibold text-slate-900">Resumo do Pedido</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Filial de destino</span>
            <p class="font-semibold text-slate-900">{{ order.branch?.name ?? '—' }} ({{ order.branch?.code ?? '—' }})</p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Fornecedor</span>
            <p class="font-semibold text-slate-900">{{ order.supplier?.name ?? '—' }}</p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Data do Pedido</span>
            <p class="text-slate-900">{{ formatDate(order.order_date) }}</p>
          </div>
          <div>
            <span class="block text-xs font-medium text-slate-500 uppercase">Valor Total</span>
            <p class="font-bold text-indigo-700">{{ formatPrice(order.total) }}</p>
          </div>
        </div>
      </div>

      <!-- Global Controls / Quick Apply -->
      <fieldset :disabled="receiving" class="rounded-xl border border-slate-200 bg-slate-50/70 p-5 shadow-sm sm:p-6 space-y-4">
        <legend class="text-sm font-semibold text-slate-800">Definições Padrão de Recebimento</legend>
        <p class="text-xs text-slate-500">
          Aplique uma localização e condição padrão para todos os itens do pedido (você pode customizar por item abaixo).
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="global-receive-location" class="block text-xs font-medium text-slate-700">Localização padrão *</label>
            <SearchableCombobox
              id="global-receive-location"
              :model-value="globalLocationId"
              :options="locationOptions"
              placeholder="Selecionar localização…"
              @update:model-value="applyGlobalLocation"
            />
          </div>
          <div>
            <label for="global-receive-condition" class="block text-xs font-medium text-slate-700">Condição padrão *</label>
            <SearchableCombobox
              id="global-receive-condition"
              :model-value="globalConditionId"
              :options="conditionOptions"
              placeholder="Selecionar condição…"
              @update:model-value="applyGlobalCondition"
            />
          </div>
        </div>
      </fieldset>

      <!-- Items Section -->
      <div class="space-y-6">
        <h2 class="text-lg font-semibold text-slate-900">Itens e Atributos de Estoque</h2>

        <div
          v-for="(item, index) in order.items"
          :key="item.id"
          class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 space-y-6"
        >
          <!-- Item Header -->
          <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-4">
            <div>
              <span class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Item {{ index + 1 }}</span>
              <h3 class="text-base font-bold text-slate-900">{{ item.product?.name ?? 'Produto' }}</h3>
              <p class="text-xs text-slate-500">SKU: {{ item.product?.sku ?? '—' }}</p>
            </div>
            <div class="text-right">
              <span class="inline-block rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-800">
                Quantidade a receber: {{ item.quantity }}
              </span>
              <p class="mt-1 text-xs text-slate-500">Custo unitário: {{ formatPrice(item.unit_cost) }}</p>
            </div>
          </div>

          <!-- Item Location & Condition -->
          <div v-if="itemsState[index]" class="grid gap-4 sm:grid-cols-2">
            <div>
              <label :for="`item-location-${index}`" class="block text-xs font-medium text-slate-700">
                Localização de estoque *
              </label>
              <SearchableCombobox
                :id="`item-location-${index}`"
                v-model="itemsState[index].stock_location_id"
                :options="locationOptions"
                placeholder="Selecionar localização…"
                required
              />
            </div>
            <div>
              <label :for="`item-condition-${index}`" class="block text-xs font-medium text-slate-700">
                Condição *
              </label>
              <SearchableCombobox
                :id="`item-condition-${index}`"
                v-model="itemsState[index].condition_id"
                :options="conditionOptions"
                placeholder="Selecionar condição…"
                required
              />
            </div>
          </div>

          <!-- Shared Attributes (if any) -->
          <div
            v-if="itemAttributes[item.product_id]?.some((d) => !d.per_unit && !d.differentiate_stock)"
            class="rounded-lg border border-slate-100 bg-slate-50/50 p-4 space-y-3"
          >
            <h4 class="text-xs font-semibold uppercase text-slate-600">Atributos Compartilhados do Produto</h4>
            <div class="grid gap-4 sm:grid-cols-2">
              <div
                v-for="attr in itemAttributes[item.product_id].filter((d) => !d.per_unit && !d.differentiate_stock)"
                :key="attr.product_attribute_id"
              >
                <label :for="`attr-${item.id}-${attr.product_attribute_id}`" class="block text-xs font-medium text-slate-700">
                  {{ attr.name }} {{ attr.required ? '*' : '' }}
                </label>

                <!-- Single selection -->
                <SearchableCombobox
                  v-if="attr.type === 'selecao_unica'"
                  :id="`attr-${item.id}-${attr.product_attribute_id}`"
                  :model-value="String(itemsState[index].sharedAttributes[attr.product_attribute_id] ?? '')"
                  :options="attributeOptions(attr)"
                  placeholder="Selecionar opção…"
                  :required="attr.required"
                  @update:model-value="(val) => itemsState[index].sharedAttributes[attr.product_attribute_id] = val"
                />

                <!-- Text / numeric -->
                <input
                  v-else-if="attr.type === 'texto' || attr.type === 'numerico'"
                  :id="`attr-${item.id}-${attr.product_attribute_id}`"
                  v-model="itemsState[index].sharedAttributes[attr.product_attribute_id]"
                  :type="attr.type === 'numerico' ? 'number' : 'text'"
                  :required="attr.required"
                  placeholder="Informe o valor…"
                  class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />

                <!-- Boolean -->
                <label v-else-if="attr.type === 'booleano'" class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                  <input
                    :id="`attr-${item.id}-${attr.product_attribute_id}`"
                    v-model="itemsState[index].sharedAttributes[attr.product_attribute_id]"
                    type="checkbox"
                    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                  />
                  <span>Sim</span>
                </label>
              </div>
            </div>
          </div>

          <!-- Per-unit Attributes (e.g. IMEI, Serial Number) -->
          <div
            v-if="itemAttributes[item.product_id]?.some((d) => d.per_unit || d.differentiate_stock)"
            class="space-y-3"
          >
            <h4 class="text-xs font-semibold uppercase text-slate-600">
              Identificação por Unidade (IMEI / Serial / Variações)
            </h4>

            <div class="grid gap-3 sm:grid-cols-2">
              <div
                v-for="(unit, unitIdx) in itemsState[index]?.units ?? []"
                :key="unitIdx"
                class="rounded-lg border border-indigo-100 bg-indigo-50/20 p-3.5 space-y-3"
              >
                <div class="flex items-center justify-between">
                  <span class="text-xs font-bold text-indigo-900">Unidade {{ unitIdx + 1 }} de {{ item.quantity }}</span>
                </div>

                <div
                  v-for="attr in itemAttributes[item.product_id].filter((d) => d.per_unit || d.differentiate_stock)"
                  :key="attr.product_attribute_id"
                >
                  <label
                    :for="`unit-${item.id}-${unitIdx}-${attr.product_attribute_id}`"
                    class="block text-xs font-medium text-slate-700"
                  >
                    {{ attr.name }} {{ attr.required || attr.differentiate_stock ? '*' : '' }}
                  </label>

                  <SearchableCombobox
                    v-if="attr.type === 'selecao_unica'"
                    :id="`unit-${item.id}-${unitIdx}-${attr.product_attribute_id}`"
                    :model-value="String(unit.attributes[attr.product_attribute_id] ?? '')"
                    :options="attributeOptions(attr)"
                    placeholder="Selecionar opção…"
                    :required="attr.required || attr.differentiate_stock"
                    @update:model-value="(val) => unit.attributes[attr.product_attribute_id] = val"
                  />

                  <input
                    v-else
                    :id="`unit-${item.id}-${unitIdx}-${attr.product_attribute_id}`"
                    v-model="unit.attributes[attr.product_attribute_id]"
                    type="text"
                    :required="attr.required || attr.differentiate_stock"
                    :placeholder="`Informe ${attr.name}…`"
                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex items-center justify-end gap-3 pt-4">
        <RouterLink
          to="/erp/compras"
          class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
        >
          Cancelar
        </RouterLink>
        <button
          type="submit"
          :disabled="receiving"
          class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50"
        >
          {{ receiving ? 'Processando entrada…' : 'Confirmar recebimento de estoque' }}
        </button>
      </div>
    </form>
  </section>
</template>

