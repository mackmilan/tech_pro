import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import PurchaseOrdersPage from './PurchaseOrdersPage.vue';

const branch = { id: 'branch-1', name: 'Matriz', code: 'MTZ-01' };
const supplier = { id: 'supplier-1', name: 'Distribuidora Tech', document: '11.222.333/0001-44' };
const product = { id: 'product-1', sku: 'IP15P', name: 'iPhone 15 Pro' };

const order = {
  id: 'order-1',
  branch_id: 'branch-1',
  supplier_id: 'supplier-1',
  number: 'PC-2026-0001',
  status: 'ordered',
  order_date: '2026-11-06',
  expected_delivery_date: '2026-11-15',
  subtotal: '9250.00',
  shipping_cost: '50.00',
  discount: '100.00',
  total: '9200.00',
  notes: 'Pedido de reposição',
  branch,
  supplier,
  items: [
    {
      id: 'item-1',
      product_id: 'product-1',
      quantity: 2,
      unit_cost: '4500.00',
      total: '9000.00',
      notes: 'Cor titânio',
      product,
    },
  ],
};

const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('PurchaseOrdersPage', () => {
  it('renders list of purchase orders, filters, opens details modal and cancels order', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/purchase-orders?')) {
        return json({ data: [order], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') {
        return json({ data: [branch] });
      }
      if (url === '/api/v1/suppliers?per_page=100') {
        return json({ data: [supplier] });
      }
      if (url === '/api/v1/purchase-orders/order-1/cancel' && init?.method === 'POST') {
        return json({ ...order, status: 'cancelled' }, 200);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/erp/compras', component: PurchaseOrdersPage },
        { path: '/erp/compras/novo', component: { template: '<div>Novo pedido</div>' } },
        { path: '/erp/compras/:id/editar', component: { template: '<div>Editar pedido</div>' } },
        { path: '/erp/compras/:id/receber', component: { template: '<div>Receber pedido</div>' } },
      ],
    });

    await router.push('/erp/compras');
    await router.isReady();

    const wrapper = mount(PurchaseOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    // Verify title and headers
    expect(wrapper.get('h1').text()).toBe('Pedidos de compra');
    expect(wrapper.text()).toContain('PC-2026-0001');
    expect(wrapper.text()).toContain('Distribuidora Tech');
    expect(wrapper.text()).toContain('Matriz (MTZ-01)');
    expect(wrapper.text()).toContain('Emitido');
    expect(wrapper.get('a[aria-label="Receber pedido PC-2026-0001"]').attributes('href')).toBe('/erp/compras/order-1/receber');

    // Filter controls: all must be SearchableCombobox, no native select
    expect(wrapper.findAll('select')).toHaveLength(0);
    const comboboxes = wrapper.findAll('input[role="combobox"]');
    expect(comboboxes.map((c) => c.attributes('id'))).toEqual([
      'purchase-branch-filter',
      'purchase-supplier-filter',
      'purchase-status-filter',
    ]);

    // Search input
    const searchInput = wrapper.get('#purchase-search');
    await searchInput.setValue('PC-2026');
    await flushPromises();
    expect(fetch.mock.calls.some(([url]) => String(url).includes('search=PC-2026'))).toBe(true);

    // Open View Details Modal
    const viewButton = wrapper.get('button[aria-label="Visualizar pedido PC-2026-0001"]');
    await viewButton.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Detalhes do Pedido PC-2026-0001');
    expect(document.body.textContent).toContain('iPhone 15 Pro');
    expect(document.body.textContent).toContain('Pedido de reposição');

    // Close Modal
    const closeButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Fechar');
    expect(closeButton).toBeDefined();
    closeButton!.click();
    await flushPromises();

    // Verify delete action does not exist
    expect(wrapper.find('button[aria-label="Excluir pedido PC-2026-0001"]').exists()).toBe(false);

    // Request cancellation
    const cancelButton = wrapper.get('button[aria-label="Cancelar pedido PC-2026-0001"]');
    await cancelButton.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Cancelar pedido de compra');
    expect(document.body.textContent).toContain('Tem certeza de que deseja cancelar o pedido de compra PC-2026-0001?');
    const confirmCancelBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Cancelar pedido');
    expect(confirmCancelBtn).toBeDefined();
    confirmCancelBtn!.click();
    await flushPromises();

    expect(fetch.mock.calls.some(([url, init]) => String(url) === '/api/v1/purchase-orders/order-1/cancel' && init?.method === 'POST')).toBe(true);

    wrapper.unmount();
  });

  it('hides edit, receive and cancel actions when order is received or cancelled', async () => {
    const receivedOrder = { ...order, id: 'order-received', number: 'PC-REC', status: 'received' };
    const cancelledOrder = { ...order, id: 'order-cancelled', number: 'PC-CAN', status: 'cancelled' };

    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.startsWith('/api/v1/purchase-orders?')) {
        return json({ data: [receivedOrder, cancelledOrder], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/suppliers?per_page=100') return json({ data: [supplier] });
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/erp/compras', component: PurchaseOrdersPage }],
    });

    await router.push('/erp/compras');
    await router.isReady();

    const wrapper = mount(PurchaseOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.find('a[aria-label="Receber pedido PC-REC"]').exists()).toBe(false);
    expect(wrapper.find('a[aria-label="Editar pedido PC-REC"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Cancelar pedido PC-REC"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Excluir pedido PC-REC"]').exists()).toBe(false);

    expect(wrapper.find('a[aria-label="Receber pedido PC-CAN"]').exists()).toBe(false);
    expect(wrapper.find('a[aria-label="Editar pedido PC-CAN"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Cancelar pedido PC-CAN"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Excluir pedido PC-CAN"]').exists()).toBe(false);

    wrapper.unmount();
  });
});
