import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import PurchaseOrderFormPage from './PurchaseOrderFormPage.vue';
import PurchaseOrdersPage from './PurchaseOrdersPage.vue';

const branch = { id: 'branch-1', name: 'Matriz', code: 'MTZ-01' };
const supplier = { id: 'supplier-1', name: 'Distribuidora Tech', document: '11.222.333/0001-44' };
const productA = { id: 'prod-1', sku: 'IP15', name: 'iPhone 15', sale_price: '5000.00' };
const productB = { id: 'prod-2', sku: 'CAPA', name: 'Capa MagSafe', sale_price: '150.00' };

const existingOrder = {
  id: 'order-1',
  branch_id: 'branch-1',
  supplier_id: 'supplier-1',
  number: 'PC-2026-EDIT',
  status: 'draft',
  order_date: '2026-11-06',
  expected_delivery_date: '2026-11-20',
  shipping_cost: '20.00',
  discount: '10.00',
  notes: 'Observação existente',
  items: [
    {
      id: 'item-1',
      product_id: 'prod-1',
      quantity: 2,
      unit_cost: '4000.00',
      notes: 'Item 1 nota',
    },
  ],
};

const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('PurchaseOrderFormPage', () => {
  it('creates a new purchase order with items and calculations', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/suppliers?per_page=100') return json({ data: [supplier] });
      if (url === '/api/v1/products?per_page=100&active=1') return json({ data: [productA, productB] });
      if (url === '/api/v1/purchase-orders' && init?.method === 'POST') {
        return json({ ...existingOrder, id: 'order-created' }, 201);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/erp/compras', component: PurchaseOrdersPage },
        { path: '/erp/compras/novo', component: PurchaseOrderFormPage },
      ],
    });

    await router.push('/erp/compras/novo');
    await router.isReady();

    const wrapper = mount(PurchaseOrderFormPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Novo pedido de compra');
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Select Branch
    const branchCombobox = wrapper.get('#po-branch');
    await branchCombobox.trigger('focus');
    await branchCombobox.setValue('Matriz');
    const branchOptions = wrapper.findAll('#po-branch-listbox [role="option"]');
    expect(branchOptions.length).toBeGreaterThan(0);
    await branchOptions[0].trigger('click');
    await flushPromises();

    // Select Supplier
    const supplierCombobox = wrapper.get('#po-supplier');
    await supplierCombobox.trigger('focus');
    await supplierCombobox.setValue('Distribuidora');
    const supplierOptions = wrapper.findAll('#po-supplier-listbox [role="option"]');
    expect(supplierOptions.length).toBeGreaterThan(0);
    await supplierOptions[0].trigger('click');
    await flushPromises();

    // Select Product in line 1
    const productCombobox = wrapper.get('#item-product-0');
    await productCombobox.trigger('focus');
    await productCombobox.setValue('iPhone');
    const productOptionsList = wrapper.findAll('#item-product-0-listbox [role="option"]');
    expect(productOptionsList.length).toBeGreaterThan(0);
    await productOptionsList[0].trigger('click');
    await flushPromises();

    // Fill line 1 quantity & unit cost
    await wrapper.get('#item-qty-0').setValue(2);
    await wrapper.get('#item-cost-0').setValue(3500);

    // Add another item
    const addItemBtn = wrapper.findAll('button').find((b) => b.text().includes('Adicionar produto'));
    expect(addItemBtn).toBeDefined();
    await addItemBtn!.trigger('click');
    await flushPromises();

    // Select Product in line 2
    const product2Combobox = wrapper.get('#item-product-1');
    await product2Combobox.trigger('focus');
    await product2Combobox.setValue('Capa');
    const product2OptionsList = wrapper.findAll('#item-product-1-listbox [role="option"]');
    expect(product2OptionsList.length).toBeGreaterThan(0);
    await product2OptionsList[0].trigger('click');
    await flushPromises();

    await wrapper.get('#item-qty-1').setValue(10);
    await wrapper.get('#item-cost-1').setValue(50);

    // Shipping and discount
    await wrapper.get('#po-shipping').setValue(100);
    await wrapper.get('#po-discount').setValue(50);

    // Verify totals: (2 * 3500) + (10 * 50) = 7000 + 500 = 7500. Total: 7500 + 100 - 50 = 7550
    expect(wrapper.text()).toContain('R$ 7.500,00');
    expect(wrapper.text()).toContain('R$ 7.550,00');

    // Submit form
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const postCall = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/purchase-orders' && init?.method === 'POST');
    expect(postCall).toBeDefined();
    const payload = JSON.parse(String(postCall?.[1]?.body));
    expect(payload.branch_id).toBe('branch-1');
    expect(payload.supplier_id).toBe('supplier-1');
    expect(payload.items).toHaveLength(2);
    expect(payload.items[0]).toMatchObject({ product_id: 'prod-1', quantity: 2, unit_cost: 3500 });
    expect(payload.items[1]).toMatchObject({ product_id: 'prod-2', quantity: 10, unit_cost: 50 });

    expect(router.currentRoute.value.fullPath).toBe('/erp/compras');
    wrapper.unmount();
  });

  it('loads and updates an existing purchase order', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/suppliers?per_page=100') return json({ data: [supplier] });
      if (url === '/api/v1/products?per_page=100&active=1') return json({ data: [productA, productB] });
      if (url === '/api/v1/purchase-orders/order-1') return json(existingOrder);
      if (url === '/api/v1/purchase-orders/order-1' && init?.method === 'PUT') {
        return json({ ...existingOrder, status: 'approved' });
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/erp/compras', component: PurchaseOrdersPage },
        { path: '/erp/compras/:id/editar', component: PurchaseOrderFormPage },
      ],
    });

    await router.push('/erp/compras/order-1/editar');
    await router.isReady();

    const wrapper = mount(PurchaseOrderFormPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Editar pedido de compra');
    expect((wrapper.get('#po-number').element as HTMLInputElement).value).toBe('PC-2026-EDIT');
    expect((wrapper.get('#item-qty-0').element as HTMLInputElement).value).toBe('2');

    // Change status to approved
    const statusCombobox = wrapper.get('#po-status');
    await statusCombobox.trigger('focus');
    await statusCombobox.setValue('Aprovado');
    const statusOptionsList = wrapper.findAll('#po-status-listbox [role="option"]');
    expect(statusOptionsList.length).toBeGreaterThan(0);
    await statusOptionsList[0].trigger('click');
    await flushPromises();

    // Submit form
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const putCall = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/purchase-orders/order-1' && init?.method === 'PUT');
    expect(putCall).toBeDefined();
    const payload = JSON.parse(String(putCall?.[1]?.body));
    expect(payload.status).toBe('approved');
    expect(router.currentRoute.value.fullPath).toBe('/erp/compras');

    wrapper.unmount();
  });
});

