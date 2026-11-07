import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SuppliersPage from './SuppliersPage.vue';

afterEach(() => vi.unstubAllGlobals());

describe('SuppliersPage', () => {
  it('lists suppliers and creates one through the form with all supplier fields', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'supplier-1', name: 'Fornecedor Alfa', document: '123', email: 'alfa@example.test', phone: '111' }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'supplier-2', name: 'Fornecedor Beta' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [
        { id: 'supplier-1', name: 'Fornecedor Alfa', document: '123', email: 'alfa@example.test', phone: '111' },
        { id: 'supplier-2', name: 'Fornecedor Beta', document: '456', email: 'beta@example.test', phone: '222' },
      ] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(SuppliersPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.findAll('tbody tr').map((row) => row.text())).toEqual([expect.stringContaining('Fornecedor Alfa')]);
    await wrapper.get('button').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Novo fornecedor');

    const fields = {
      '#supplier-name': 'Fornecedor Beta',
      '#supplier-document': '456',
      '#supplier-email': 'beta@example.test',
      '#supplier-phone': '222',
    };
    for (const [selector, value] of Object.entries(fields)) {
      const input = document.body.querySelector<HTMLInputElement>(selector);
      expect(input).not.toBeNull();
      input!.value = value;
      input!.dispatchEvent(new Event('input', { bubbles: true }));
    }
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/suppliers');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({
      name: 'Fornecedor Beta', document: '456', email: 'beta@example.test', phone: '222',
    });
    expect(wrapper.text()).toContain('Fornecedor Beta');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    wrapper.unmount();
  });

  it('loads an existing supplier into the edit form and submits an update', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'supplier-1', name: 'Fornecedor Alfa', document: '123', email: 'alfa@example.test', phone: '111' }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'supplier-1', name: 'Fornecedor Alfa Atualizado', document: '999', email: 'novo@example.test', phone: '222' }] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(SuppliersPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    await wrapper.get('[aria-label="Editar Fornecedor Alfa"]').trigger('click');
    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Editar fornecedor');
    const name = document.body.querySelector<HTMLInputElement>('#supplier-name')!;
    expect(name.value).toBe('Fornecedor Alfa');
    name.value = 'Fornecedor Alfa Atualizado';
    name.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/suppliers/supplier-1');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'PUT' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ name: 'Fornecedor Alfa Atualizado' });
    expect(wrapper.text()).toContain('Fornecedor Alfa Atualizado');
    wrapper.unmount();
  });

  it('asks for confirmation before deleting a supplier and refreshes the list after confirmation', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'supplier-1', name: 'Fornecedor Alfa', document: null, email: null, phone: null }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(SuppliersPage, {
      global: { stubs: { RouterLink: true, ConfirmationDialog: true } },
    });
    await flushPromises();

    await wrapper.get('[aria-label="Excluir Fornecedor Alfa"]').trigger('click');
    expect(wrapper.getComponent({ name: 'ConfirmationDialog' }).props('modelValue')).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(1);

    await wrapper.getComponent({ name: 'ConfirmationDialog' }).vm.$emit('confirm');
    await flushPromises();
    expect(fetch.mock.calls[1][0]).toBe('/api/v1/suppliers/supplier-1');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'DELETE' });
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(wrapper.text()).toContain('Nenhum fornecedor cadastrado.');
    wrapper.unmount();
  });
});
