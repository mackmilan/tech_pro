import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TechniciansPage from './TechniciansPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('TechniciansPage', () => {
  it('lists technicians, filters, and creates a new technician through modal form', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'tech-1',
              name: 'Lucas Manutenção',
              email: 'lucas@assistencia.com',
              phone: '(11) 98765-4321',
              document: '123.456.789-00',
              specialty: 'Apple e Troca de Telas',
              commission_rate: '12.50',
              active: true,
              notes: 'Bancada 1',
              user_id: null,
            },
          ],
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          id: 'tech-2',
          name: 'Renata Solda',
          email: 'renata@assistencia.com',
          phone: '(11) 91234-5678',
          document: '987.654.321-00',
          specialty: 'Microssoldagem',
          commission_rate: '15.00',
          active: true,
          notes: 'Bancada 2',
          user_id: null,
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'tech-1',
              name: 'Lucas Manutenção',
              email: 'lucas@assistencia.com',
              phone: '(11) 98765-4321',
              document: '123.456.789-00',
              specialty: 'Apple e Troca de Telas',
              commission_rate: '12.50',
              active: true,
              notes: 'Bancada 1',
              user_id: null,
            },
            {
              id: 'tech-2',
              name: 'Renata Solda',
              email: 'renata@assistencia.com',
              phone: '(11) 91234-5678',
              document: '987.654.321-00',
              specialty: 'Microssoldagem',
              commission_rate: '15.00',
              active: true,
              notes: 'Bancada 2',
              user_id: null,
            },
          ],
        }),
      });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(TechniciansPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Técnicos');
    expect(wrapper.text()).toContain('Lucas Manutenção');
    expect(wrapper.text()).toContain('Apple e Troca de Telas');
    expect(wrapper.text()).toContain('12,5%');
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Open modal
    const newBtn = wrapper.findAll('button').find((b) => b.text().includes('Novo técnico'));
    expect(newBtn).toBeDefined();
    await newBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Novo Técnico');

    const nameInput = document.body.querySelector<HTMLInputElement>('#tech-name')!;
    const phoneInput = document.body.querySelector<HTMLInputElement>('#tech-phone')!;
    const specialtyInput = document.body.querySelector<HTMLInputElement>('#tech-specialty')!;
    const commissionInput = document.body.querySelector<HTMLInputElement>('#tech-commission')!;

    nameInput.value = 'Renata Solda';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    phoneInput.value = '(11) 91234-5678';
    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));

    specialtyInput.value = 'Microssoldagem';
    specialtyInput.dispatchEvent(new Event('input', { bubbles: true }));

    commissionInput.value = '15';
    commissionInput.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/technicians',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"name":"Renata Solda"'),
      })
    );

    expect(wrapper.text()).toContain('Renata Solda');

    wrapper.unmount();
  });

  it('edits and deletes a technician', async () => {
    const tech = {
      id: 'tech-1',
      name: 'Lucas Manutenção',
      email: 'lucas@assistencia.com',
      phone: '(11) 98765-4321',
      document: '123.456.789-00',
      specialty: 'Apple e Troca de Telas',
      commission_rate: '12.50',
      active: true,
      notes: null,
      user_id: null,
    };

    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [tech] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ ...tech, name: 'Lucas Silva Atualizado' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ ...tech, name: 'Lucas Silva Atualizado' }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(TechniciansPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    // Edit button
    const editBtn = wrapper.findAll('button').find((b) => b.text().includes('Editar'));
    expect(editBtn).toBeDefined();
    await editBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Editar Técnico');

    const nameInput = document.body.querySelector<HTMLInputElement>('#tech-name')!;
    nameInput.value = 'Lucas Silva Atualizado';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/technicians/tech-1',
      expect.objectContaining({
        method: 'PUT',
        body: expect.stringContaining('"name":"Lucas Silva Atualizado"'),
      })
    );

    // Delete button
    const deleteBtn = wrapper.findAll('button').find((b) => b.text().includes('Excluir'));
    expect(deleteBtn).toBeDefined();
    await deleteBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Excluir técnico');

    const confirmBtn = document.body.querySelector<HTMLButtonElement>('[role="dialog"] button.bg-red-600')!;
    confirmBtn.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/technicians/tech-1',
      expect.objectContaining({
        method: 'DELETE',
      })
    );

    wrapper.unmount();
  });
});

