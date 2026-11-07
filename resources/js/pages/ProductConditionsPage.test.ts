import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProductConditionsPage from './ProductConditionsPage.vue';

afterEach(() => vi.unstubAllGlobals());

describe('ProductConditionsPage', () => {
  it('lists conditions and creates one through the modal form', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'condition-1', name: 'Usado', active: true }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'condition-2', name: 'Novo', active: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        { id: 'condition-2', name: 'Novo', active: true },
        { id: 'condition-1', name: 'Usado', active: true },
      ] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(ProductConditionsPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.findAll('tbody tr').map((row) => row.text())).toEqual([expect.stringContaining('Usado')]);
    await wrapper.get('button').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Nova condição');
    const input = document.body.querySelector<HTMLInputElement>('#condition-name');
    expect(input).not.toBeNull();
    input!.value = 'Novo';
    input!.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/product-conditions');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ name: 'Novo', active: true });
    expect(wrapper.text()).toContain('Novo');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });
});
