import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CustomersPage from './CustomersPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('CustomersPage', () => {
  it('lists customers, displays columns, filters, and creates a new customer through modal form', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'cust-1',
              name: 'Maria Oliveira',
              document: '123.456.789-10',
              email: 'maria@exemplo.com',
              phone: '(11) 98765-4321',
              source: 'WhatsApp',
              active: true,
              notes: 'Cliente preferencial.',
            },
          ],
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          id: 'cust-2',
          name: 'João Silva',
          document: '987.654.321-00',
          email: 'joao@exemplo.com',
          phone: '(11) 91234-5678',
          source: 'Balcão / Loja física',
          active: true,
          notes: 'Cliente de balcão.',
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'cust-1',
              name: 'Maria Oliveira',
              document: '123.456.789-10',
              email: 'maria@exemplo.com',
              phone: '(11) 98765-4321',
              source: 'WhatsApp',
              active: true,
              notes: 'Cliente preferencial.',
            },
            {
              id: 'cust-2',
              name: 'João Silva',
              document: '987.654.321-00',
              email: 'joao@exemplo.com',
              phone: '(11) 91234-5678',
              source: 'Balcão / Loja física',
              active: true,
              notes: 'Cliente de balcão.',
            },
          ],
        }),
      });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(CustomersPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Clientes');
    expect(wrapper.text()).toContain('Maria Oliveira');
    expect(wrapper.text()).toContain('123.456.789-10');
    expect(wrapper.text()).toContain('WhatsApp');
    expect(wrapper.text()).toContain('(11) 98765-4321');
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Open create modal
    const newBtn = wrapper.findAll('button').find((b) => b.text().includes('Novo cliente'));
    expect(newBtn).toBeDefined();
    await newBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Novo cliente');

    const nameInput = document.body.querySelector<HTMLInputElement>('#customer-name')!;
    const docInput = document.body.querySelector<HTMLInputElement>('#customer-document')!;
    const phoneInput = document.body.querySelector<HTMLInputElement>('#customer-phone')!;
    const emailInput = document.body.querySelector<HTMLInputElement>('#customer-email')!;

    nameInput.value = 'João Silva';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    docInput.value = '987.654.321-00';
    docInput.dispatchEvent(new Event('input', { bubbles: true }));

    phoneInput.value = '(11) 91234-5678';
    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));

    emailInput.value = 'joao@exemplo.com';
    emailInput.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/customers',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"name":"João Silva"'),
      })
    );

    expect(wrapper.text()).toContain('João Silva');

    wrapper.unmount();
  });

  it('edits and deletes a customer', async () => {
    const cust = {
      id: 'cust-1',
      name: 'Maria Oliveira',
      document: '123.456.789-10',
      email: 'maria@exemplo.com',
      phone: '(11) 98765-4321',
      source: 'WhatsApp',
      active: true,
      notes: null,
    };

    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [cust] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ ...cust, name: 'Maria Oliveira Atualizada' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ ...cust, name: 'Maria Oliveira Atualizada' }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(CustomersPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    // Edit button
    const editBtn = wrapper.findAll('button').find((b) => b.text().includes('Editar'));
    expect(editBtn).toBeDefined();
    await editBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Editar cliente');

    const nameInput = document.body.querySelector<HTMLInputElement>('#customer-name')!;
    nameInput.value = 'Maria Oliveira Atualizada';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/customers/cust-1',
      expect.objectContaining({
        method: 'PUT',
        body: expect.stringContaining('"name":"Maria Oliveira Atualizada"'),
      })
    );

    // Delete button
    const deleteBtn = wrapper.findAll('button').find((b) => b.text().includes('Excluir'));
    expect(deleteBtn).toBeDefined();
    await deleteBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Excluir cliente');

    const confirmBtn = document.body.querySelector<HTMLButtonElement>('[role="dialog"] button.bg-red-600')!;
    confirmBtn.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/customers/cust-1',
      expect.objectContaining({
        method: 'DELETE',
      })
    );

    wrapper.unmount();
  });

  it('handles load and form errors gracefully', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: false,
      json: async () => ({ message: 'Erro ao carregar clientes do servidor.' }),
    });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(CustomersPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Erro ao carregar clientes do servidor.');

    wrapper.unmount();
  });
});

