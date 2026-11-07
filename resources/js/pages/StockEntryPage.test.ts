import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterLink } from 'vue-router';
import StockEntryPage from './StockEntryPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

function createRouterForEntry() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/erp/estoque/novo-lancamento', component: StockEntryPage },
      { path: '/erp/estoque', component: { template: '<h1>Estoque</h1>' } },
    ],
  });
}

describe('StockEntryPage', () => {
  it('loads lookups, renders product stock attributes and persists the inbound entry', async () => {
    const colorAttribute = { product_attribute_id: 'attr-color', name: 'Cor', type: 'selecao_multipla', options: ['Preto', 'Azul'], required: true, differentiate_stock: true };
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/products?per_page=100&active=1') return { ok: true, json: async () => ({ data: [{ id: 'product-1', sku: 'CEL-1', name: 'Celular A', active: true }] }) };
      if (url === '/api/v1/branches?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Filial Centro', code: 'CENTRO', stock: { id: 'stock-1' } }] }) };
      if (url === '/api/v1/stock-locations?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'location-1', stock_id: 'stock-1', name: 'Depósito', code: 'DEP' }] }) };
      if (url === '/api/v1/product-conditions?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'condition-1', name: 'Novo', active: true }] }) };
      if (url === '/api/v1/products/product-1/stock-attributes') return { ok: true, json: async () => ({ data: [
        { ...colorAttribute, options: colorAttribute.options, differentiate_stock: true, per_unit: false, entity_type: 'produto' },
        { product_attribute_id: 'attr-text', name: 'Lote', type: 'texto', options: null, entity_type: 'estoque', differentiate_stock: false, per_unit: false, required: true },
        { product_attribute_id: 'attr-multiple', name: 'Recursos', type: 'selecao_multipla', options: ['5G', 'NFC'], entity_type: 'estoque', differentiate_stock: false, per_unit: false, required: false },
      ] }) };
      if (url === '/api/v1/stock-movements' && init?.method === 'POST') return { ok: true, status: 201, json: async () => ({ id: 'movement-1' }) };
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);

    const router = createRouterForEntry();
    await router.push('/erp/estoque/novo-lancamento');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();
    expect(wrapper.get('h1').text()).toBe('Lançar entrada de estoque');

    async function selectOption(id: string, text: string) {
      const input = document.body.querySelector<HTMLInputElement>(`#${id}`)!;
      input.focus();
      input.value = text;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      await flushPromises();
      [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find((option) => option.textContent?.includes(text))?.click();
      await flushPromises();
    }

    await selectOption('stock-entry-stock', 'Filial Centro');
    await selectOption('stock-entry-location', 'Depósito');
    await selectOption('stock-entry-product', 'Celular A');
    const colorCombobox = document.body.querySelector<HTMLInputElement>('[data-testid="stock-entry-attribute-attr-color-0"]');
    expect(colorCombobox?.getAttribute('role')).toBe('combobox');
    expect(wrapper.text()).toContain('Cor');
    expect(wrapper.text()).toContain('Custo unitário');
    await selectOption('stock-entry-attribute-attr-color-0', 'Preto');
    await selectOption('stock-entry-unit-condition-0', 'Novo');
    document.body.querySelector<HTMLInputElement>('#stock-entry-unit-cost-0')!.value = '100';
    document.body.querySelector<HTMLInputElement>('#stock-entry-unit-cost-0')!.dispatchEvent(new Event('input', { bubbles: true }));
    await selectOption('stock-entry-attribute-attr-text', 'LOTE-42');

    await selectOption('stock-entry-attribute-attr-multiple', '5G');
    await selectOption('stock-entry-attribute-attr-multiple', 'NFC');
    document.body.querySelector<HTMLFormElement>('form[aria-label="Lançamento de entrada"]')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
    const request = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/stock-movements' && init?.method === 'POST');
    expect(JSON.parse(String(request?.[1]?.body))).toMatchObject({
      quantity: 1,
      attributes: [
        { product_attribute_id: 'attr-text', value: 'LOTE-42' },
        { product_attribute_id: 'attr-multiple', value: ['5G', 'NFC'] },
      ],
      units: [{ condition_id: 'condition-1', unit_cost: 100, attributes: [{ product_attribute_id: 'attr-color', value: 'Preto' }] }],
    });
    expect(router.currentRoute.value.path).toBe('/erp/estoque');
    wrapper.unmount();
  });

  it('collects condition, unit cost, and per-unit attributes for each received unit', async () => {
    const attribute = { product_attribute_id: 'attr-imei', name: 'IMEI', type: 'texto', options: null, required: true, differentiate_stock: false, per_unit: true };
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/products?per_page=100&active=1') return { ok: true, json: async () => ({ data: [{ id: 'product-1', sku: 'CEL-1', name: 'Celular A', active: true }] }) };
      if (url === '/api/v1/branches?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Filial Centro', code: 'CENTRO', stock: { id: 'stock-1' } }] }) };
      if (url === '/api/v1/stock-locations?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'location-1', stock_id: 'stock-1', name: 'Depósito', code: 'DEP' }] }) };
      if (url === '/api/v1/product-conditions?per_page=100') return { ok: true, json: async () => ({ data: [{ id: 'condition-new', name: 'Novo', active: true }, { id: 'condition-used', name: 'Seminovo', active: true }] }) };
      if (url === '/api/v1/products/product-1/stock-attributes') return { ok: true, json: async () => ({ data: [attribute] }) };
      if (url === '/api/v1/stock-movements' && init?.method === 'POST') return { ok: true, status: 201, json: async () => ({ id: 'movement-1' }) };
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouterForEntry();
    await router.push('/erp/estoque/novo-lancamento');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();
    async function selectOption(id: string, text: string) {
      const input = document.body.querySelector<HTMLInputElement>(`#${id}`)!;
      input.focus(); input.value = text; input.dispatchEvent(new Event('input', { bubbles: true })); await flushPromises();
      [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find((option) => option.textContent?.includes(text))?.click(); await flushPromises();
    }
    await selectOption('stock-entry-stock', 'Filial Centro');
    await selectOption('stock-entry-location', 'Depósito');
    await selectOption('stock-entry-product', 'Celular A');
    const quantity = document.body.querySelector<HTMLInputElement>('#stock-entry-quantity')!;
    quantity.value = '2'; quantity.dispatchEvent(new Event('input', { bubbles: true })); await flushPromises();
    expect(wrapper.text()).toContain('Unidade 1');
    expect(wrapper.text()).toContain('Unidade 2');
    for (const [index, identifier, conditionName, cost] of [['0', 'IMEI-1', 'Novo', '100'], ['1', 'IMEI-2', 'Seminovo', '80']] as const) {
      await selectOption(`stock-entry-unit-condition-${index}`, conditionName);
      const costInput = document.body.querySelector<HTMLInputElement>(`#stock-entry-unit-cost-${index}`)!;
      costInput.value = cost; costInput.dispatchEvent(new Event('input', { bubbles: true }));
      const input = document.body.querySelector<HTMLInputElement>(`#stock-entry-attribute-attr-imei-${index}`)!;
      input.value = identifier; input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    document.body.querySelector<HTMLFormElement>('form[aria-label="Lançamento de entrada"]')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
    const request = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/stock-movements' && init?.method === 'POST');
    expect(JSON.parse(String(request?.[1]?.body))).toMatchObject({
      quantity: 2,
      units: [
        { condition_id: 'condition-new', unit_cost: 100, attributes: [{ product_attribute_id: 'attr-imei', value: 'IMEI-1' }] },
        { condition_id: 'condition-used', unit_cost: 80, attributes: [{ product_attribute_id: 'attr-imei', value: 'IMEI-2' }] },
      ],
    });
    wrapper.unmount();
  });

  it('uses the full available width for the entry screen', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) }));
    const router = createRouterForEntry();
    await router.push('/erp/estoque/novo-lancamento');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    const page = wrapper.get('section');
    expect(page.classes()).toContain('w-full');
    expect(page.classes().some((className) => className.startsWith('max-w-'))).toBe(false);
    wrapper.unmount();
  });

  it('provides a return link to the stock listing', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const router = createRouterForEntry();
    await router.push('/erp/estoque/novo-lancamento');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();
    const link = wrapper.findAllComponents(RouterLink).find((candidate) => candidate.props('to') === '/erp/estoque');
    expect(link).toBeDefined();
    wrapper.unmount();
  });
});
