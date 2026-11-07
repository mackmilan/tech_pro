import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterLink } from 'vue-router';
import ModulePage from './ModulePage.vue';
import StockEntryPage from './StockEntryPage.vue';
import StockPage from './StockPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

function createTestRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:module(crm|erp|caixa|configuracoes)', component: ModulePage },
      { path: '/erp/estoque', component: StockPage },
      { path: '/erp/estoque/novo-lancamento', component: StockEntryPage },
      { path: '/:module(crm|erp|caixa|configuracoes)/:pathMatch(.*)*', component: ModulePage },
    ],
  });
}

describe('stock navigation', () => {
  it('links the ERP stock card to the stock listing page', async () => {
    const router = createTestRouter();
    await router.push('/erp');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    const link = wrapper.findAllComponents(RouterLink).find((candidate) => candidate.props('to') === '/erp/estoque');
    expect(link).toBeDefined();
    await link!.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/erp/estoque');
    expect(wrapper.get('h1').text()).toBe('Estoque');
    wrapper.unmount();
  });

  it('links from the stock list to the dedicated entry page and renders it', async () => {
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url === '/api/v1/stock-items?per_page=100') return { ok: true, json: async () => ({ data: [] }) };
      if (url === '/api/v1/products?per_page=100&active=1') return { ok: true, json: async () => ({ data: [] }) };
      if (url === '/api/v1/branches?per_page=100') return { ok: true, json: async () => ({ data: [] }) };
      if (url === '/api/v1/stock-locations?per_page=100') return { ok: true, json: async () => ({ data: [] }) };
      if (url === '/api/v1/product-conditions?per_page=100') return { ok: true, json: async () => ({ data: [] }) };
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createTestRouter();
    await router.push('/erp/estoque');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();
    await wrapper.get('a[aria-label="Lançar entrada de estoque"]').trigger('click');
    await flushPromises();

    expect(router.currentRoute.value.path).toBe('/erp/estoque/novo-lancamento');
    expect(wrapper.get('h1').text()).toBe('Lançar entrada de estoque');
    expect(fetch).toHaveBeenCalledWith('/api/v1/products?per_page=100&active=1', { headers: { Accept: 'application/json' } });
    wrapper.unmount();
  });

  it('renders the stock page on its route and requests stock items', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const router = createTestRouter();
    await router.push('/erp/estoque');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Estoque');
    expect(fetch).toHaveBeenCalledWith('/api/v1/stock-items?per_page=100', { headers: { Accept: 'application/json' } });
    wrapper.unmount();
  });
});
