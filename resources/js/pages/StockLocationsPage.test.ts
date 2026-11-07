import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterLink } from 'vue-router';
import ModulePage from './ModulePage.vue';
import StockLocationsPage from './StockLocationsPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

function createTestRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:module(crm|erp|caixa|configuracoes)', component: ModulePage },
      { path: '/erp/localizacoes', component: StockLocationsPage },
      { path: '/:module(crm|erp|caixa|configuracoes)/:pathMatch(.*)*', component: ModulePage },
    ],
  });
}

describe('StockLocationsPage', () => {
  it('lists locations, filters them by stock and creates one in a modal', async () => {
    const branchPage = { data: [{ id: 'branch-1', name: 'Centro', code: 'CENTRO', stock: { id: 'stock-1' } }, { id: 'branch-2', name: 'Norte', code: 'NORTE', stock: { id: 'stock-2' } }] };
    const locationPage = { data: [
        { id: 'loc-1', stock_id: 'stock-1', name: 'Depósito Centro', code: 'DEP', type: 'storage', stock: { branch: { name: 'Centro', code: 'CENTRO' } } },
        { id: 'loc-2', stock_id: 'stock-2', name: 'Prateleira Norte', code: 'PRAT', type: 'shelf', stock: { branch: { name: 'Norte', code: 'NORTE' } } },
      ] };
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => branchPage })
      .mockResolvedValueOnce({ ok: true, json: async () => locationPage })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'loc-3' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => branchPage })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        locationPage.data[0],
        { id: 'loc-3', stock_id: 'stock-1', name: 'Recebimento', code: 'REC', type: 'storage', stock: { branch: { name: 'Centro', code: 'CENTRO' } } },
      ] }) });
    vi.stubGlobal('fetch', fetch);
    vi.stubGlobal('document', document);

    const router = createTestRouter();
    await router.push('/erp/localizacoes');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Localizações de estoque');
    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Depósito Centro');
    expect(wrapper.text()).toContain('Prateleira Norte');
    const stockFilter = wrapper.get('#location-stock-filter');
    await stockFilter.trigger('focus');
    await stockFilter.setValue('Centro');
    await wrapper.get('[role="option"]').trigger('click');
    expect(wrapper.text()).toContain('Depósito Centro');
    expect(wrapper.text()).not.toContain('Prateleira Norte');

    await wrapper.get('button').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Nova localização');
    const fields = { '#location-name': 'Recebimento', '#location-code': 'REC' };
    for (const [selector, value] of Object.entries(fields)) {
      const input = document.body.querySelector<HTMLInputElement>(selector)!;
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    const stock = document.body.querySelector<HTMLInputElement>('#location-stock')!;
    stock.focus();
    stock.value = 'Centro';
    stock.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    const stockType = document.body.querySelector<HTMLInputElement>('#location-type')!;
    stockType.focus();
    stockType.value = 'Prateleira';
    stockType.dispatchEvent(new Event('input', { bubbles: true }));
    document.body.querySelector('[role="option"]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[2][0]).toBe('/api/v1/stock-locations');
    expect(fetch.mock.calls[2][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ stock_id: 'stock-1', name: 'Recebimento', code: 'REC', type: 'storage' });
    expect(wrapper.text()).toContain('Recebimento');
    wrapper.unmount();
  });

  it('edits an existing location and confirms before soft deletion', async () => {
    const branchPage = { data: [{ id: 'branch-1', name: 'Centro', code: 'CENTRO', stock: { id: 'stock-1' } }] };
    const locationPage = { data: [
      { id: 'loc-1', stock_id: 'stock-1', name: 'Depósito', code: 'DEP', type: 'storage', stock: { branch: { name: 'Centro', code: 'CENTRO' } } },
    ] };
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => branchPage })
      .mockResolvedValueOnce({ ok: true, json: async () => locationPage })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => branchPage })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        { ...locationPage.data[0], name: 'Depósito Central', code: 'DEP-C' },
      ] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => branchPage })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const router = createTestRouter();
    await router.push('/erp/localizacoes');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();

    await wrapper.get('[aria-label="Editar Depósito"]').trigger('click');
    const name = document.body.querySelector<HTMLInputElement>('#location-name')!;
    name.value = 'Depósito Central';
    name.dispatchEvent(new Event('input', { bubbles: true }));
    const code = document.body.querySelector<HTMLInputElement>('#location-code')!;
    code.value = 'DEP-C';
    code.dispatchEvent(new Event('input', { bubbles: true }));
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
    expect(fetch.mock.calls[2][0]).toBe('/api/v1/stock-locations/loc-1');
    expect(fetch.mock.calls[2][1]).toMatchObject({ method: 'PUT' });
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ stock_id: 'stock-1', name: 'Depósito Central', code: 'DEP-C', type: 'storage' });

    await wrapper.get('[aria-label="Excluir Depósito Central"]').trigger('click');
    expect(wrapper.findComponent({ name: 'ConfirmationDialog' }).props('modelValue')).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(5);
    await wrapper.findComponent({ name: 'ConfirmationDialog' }).vm.$emit('confirm');
    await flushPromises();
    expect(fetch.mock.calls[5][0]).toBe('/api/v1/stock-locations/loc-1');
    expect(fetch.mock.calls[5][1]).toMatchObject({ method: 'DELETE' });
    wrapper.unmount();
  });

  it('exposes stock locations from both the ERP card and its sidebar destination', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const router = createTestRouter();
    await router.push('/erp');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();

    const cardLink = wrapper.findAllComponents(RouterLink).find((link) => link.props('to') === '/erp/localizacoes');
    expect(cardLink).toBeDefined();
    await cardLink!.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/erp/localizacoes');
    expect(wrapper.get('h1').text()).toBe('Localizações de estoque');
    expect(fetch).toHaveBeenCalledWith('/api/v1/stock-locations?per_page=100', { headers: { Accept: 'application/json' } });
    wrapper.unmount();
  });
});
