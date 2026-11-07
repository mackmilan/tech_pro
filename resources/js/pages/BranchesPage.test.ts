import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BranchesPage from './BranchesPage.vue';

afterEach(() => vi.unstubAllGlobals());

describe('BranchesPage', () => {
  it('lists branches and creates one with its branch fields', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Matriz', code: 'MATRIZ', document: null, active: true }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'branch-2', name: 'Centro', code: 'CENTRO', document: '123', active: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        { id: 'branch-1', name: 'Matriz', code: 'MATRIZ', document: null, active: true },
        { id: 'branch-2', name: 'Centro', code: 'CENTRO', document: '123', active: true },
      ] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(BranchesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Matriz');
    await wrapper.get('button').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Nova filial');

    const fields = { '#branch-name': 'Centro', '#branch-code': 'CENTRO', '#branch-document': '123' };
    for (const [selector, value] of Object.entries(fields)) {
      const input = document.body.querySelector<HTMLInputElement>(selector);
      expect(input).not.toBeNull();
      input!.value = value;
      input!.dispatchEvent(new Event('input', { bubbles: true }));
    }
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/branches');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ name: 'Centro', code: 'CENTRO', document: '123', active: true });
    expect(wrapper.text()).toContain('Centro');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });

  it('loads an existing branch and updates it', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Matriz', code: 'MATRIZ', document: '111', active: true }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Matriz Sul', code: 'MATRIZ', document: '111', active: false }] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(BranchesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    await wrapper.get('[aria-label="Editar Matriz"]').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Editar filial');
    const name = document.body.querySelector<HTMLInputElement>('#branch-name')!;
    expect(name.value).toBe('Matriz');
    name.value = 'Matriz Sul';
    name.dispatchEvent(new Event('input', { bubbles: true }));
    const active = document.body.querySelector<HTMLInputElement>('#branch-active')!;
    active.checked = false;
    active.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/branches/branch-1');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'PUT' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ name: 'Matriz Sul', code: 'MATRIZ', document: '111', active: false });
    expect(wrapper.text()).toContain('Matriz Sul');
    wrapper.unmount();
  });

  it('requires explicit confirmation before deleting a branch', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'branch-1', name: 'Matriz', code: 'MATRIZ', document: null, active: true }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(BranchesPage, { global: { stubs: { RouterLink: true, ConfirmationDialog: true } } });
    await flushPromises();

    await wrapper.get('[aria-label="Excluir Matriz"]').trigger('click');
    expect(wrapper.getComponent({ name: 'ConfirmationDialog' }).props('modelValue')).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(1);
    await wrapper.getComponent({ name: 'ConfirmationDialog' }).vm.$emit('confirm');
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/branches/branch-1');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'DELETE' });
    expect(wrapper.text()).toContain('Nenhuma filial cadastrada.');
    wrapper.unmount();
  });
});
