import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import PurchaseOrderReceivePage from './PurchaseOrderReceivePage.vue';
import PurchaseOrdersPage from './PurchaseOrdersPage.vue';

const branch = { id: 'branch-1', name: 'Matriz', code: 'MTZ-01', stock: { id: 'stock-1' } };
const supplier = { id: 'supplier-1', name: 'Distribuidora Tech', document: '11.222.333/0001-44' };
const product = { id: 'prod-1', sku: 'IP15', name: 'iPhone 15' };

const order = {
  id: 'order-1',
  branch_id: 'branch-1',
  supplier_id: 'supplier-1',
  number: 'PC-2026-REC',
  status: 'ordered',
  order_date: '2026-11-06',
  expected_delivery_date: '2026-11-20',
  subtotal: '8000.00',
  shipping_cost: '0.00',
  discount: '0.00',
  total: '8000.00',
  notes: 'Para receber',
  branch,
  supplier,
  items: [
    {
      id: 'item-1',
      product_id: 'prod-1',
      quantity: 2,
      unit_cost: '4000.00',
      total: '8000.00',
      notes: null,
      product,
    },
  ],
};

const locations = [
  { id: 'loc-1', stock_id: 'stock-1', name: 'Depósito Central', code: 'DEP-01' },
];

const conditions = [
  { id: 'cond-1', name: 'Novo', active: true },
];

const stockAttributes = [
  {
    product_attribute_id: 'attr-imei',
    name: 'IMEI',
    entity_type: 'estoque',
    type: 'texto',
    options: null,
    required: true,
    differentiate_stock: false,
    per_unit: true,
  },
];

const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('PurchaseOrderReceivePage', () => {
  it('loads purchase order, displays stock attributes and submits receiving', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/purchase-orders/order-1') return json(order);
      if (url === '/api/v1/product-conditions?per_page=100') return json({ data: conditions });
      if (url.startsWith('/api/v1/stock-locations?')) return json({ data: locations });
      if (url === '/api/v1/products/prod-1/stock-attributes') return json({ data: stockAttributes });
      if (url === '/api/v1/purchase-orders/order-1/receive' && init?.method === 'POST') {
        return json({ ...order, status: 'received' });
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/erp/compras', component: PurchaseOrdersPage },
        { path: '/erp/compras/:id/receber', component: PurchaseOrderReceivePage },
      ],
    });

    await router.push('/erp/compras/order-1/receber');
    await router.isReady();

    const wrapper = mount(PurchaseOrderReceivePage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toContain('Receber pedido de compra PC-2026-REC');
    expect(wrapper.text()).toContain('iPhone 15');
    expect(wrapper.text()).toContain('Unidade 1 de 2');
    expect(wrapper.text()).toContain('Unidade 2 de 2');
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Fill IMEI for Unit 1 and Unit 2
    const imeiInputs = wrapper.findAll('input[placeholder="Informe IMEI…"]');
    expect(imeiInputs).toHaveLength(2);

    await imeiInputs[0].setValue('111111111111111');
    await imeiInputs[1].setValue('222222222222222');

    // Submit receive form
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const receiveCall = fetch.mock.calls.find(
      ([url, init]) => String(url) === '/api/v1/purchase-orders/order-1/receive' && init?.method === 'POST'
    );
    expect(receiveCall).toBeDefined();

    const payload = JSON.parse(String(receiveCall?.[1]?.body));
    expect(payload.items).toHaveLength(1);
    expect(payload.items[0].purchase_order_item_id).toBe('item-1');
    expect(payload.items[0].stock_location_id).toBe('loc-1');
    expect(payload.items[0].condition_id).toBe('cond-1');
    expect(payload.items[0].units).toHaveLength(2);
    expect(payload.items[0].units[0].attributes).toEqual([
      { product_attribute_id: 'attr-imei', value: '111111111111111' },
    ]);
    expect(payload.items[0].units[1].attributes).toEqual([
      { product_attribute_id: 'attr-imei', value: '222222222222222' },
    ]);

    expect(router.currentRoute.value.fullPath).toBe('/erp/compras');
    wrapper.unmount();
  });

  it('displays message when order is already received', async () => {
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url === '/api/v1/purchase-orders/order-1') {
        return json({ ...order, status: 'received', received_at: '2026-11-06' });
      }
      if (url === '/api/v1/product-conditions?per_page=100') return json({ data: conditions });
      if (url.startsWith('/api/v1/stock-locations?')) return json({ data: locations });
      if (url === '/api/v1/products/prod-1/stock-attributes') return json({ data: stockAttributes });
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/erp/compras', component: PurchaseOrdersPage },
        { path: '/erp/compras/:id/receber', component: PurchaseOrderReceivePage },
      ],
    });

    await router.push('/erp/compras/order-1/receber');
    await router.isReady();

    const wrapper = mount(PurchaseOrderReceivePage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Pedido já recebido');
    expect(wrapper.find('form').exists()).toBe(false);
    wrapper.unmount();
  });
});

