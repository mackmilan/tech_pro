import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ServicesPage from './ServicesPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('ServicesPage', () => {
  it('lists services and creates a new service through modal form', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'srv-1',
              name: 'Troca de Tela',
              code: 'SRV-TELA',
              price: '150.00',
              estimated_minutes: 60,
              warranty_days: 90,
              active: true,
            },
          ],
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          id: 'srv-2',
          name: 'Troca de Conector',
          code: 'SRV-CON',
          price: '120.00',
          estimated_minutes: 45,
          warranty_days: 90,
          active: true,
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'srv-1',
              name: 'Troca de Tela',
              code: 'SRV-TELA',
              price: '150.00',
              estimated_minutes: 60,
              warranty_days: 90,
              active: true,
            },
            {
              id: 'srv-2',
              name: 'Troca de Conector',
              code: 'SRV-CON',
              price: '120.00',
              estimated_minutes: 45,
              warranty_days: 90,
              active: true,
            },
          ],
        }),
      });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ServicesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Catálogo de Serviços');
    expect(wrapper.text()).toContain('Troca de Tela');
    expect(wrapper.text()).toContain('SRV-TELA');

    // Click "Novo serviço" button
    const newBtn = wrapper.findAll('button').find((b) => b.text().includes('Novo serviço'));
    expect(newBtn).toBeDefined();
    await newBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Novo serviço');

    const nameInput = document.body.querySelector<HTMLInputElement>('#srv-name');
    const codeInput = document.body.querySelector<HTMLInputElement>('#srv-code');
    const priceInput = document.body.querySelector<HTMLInputElement>('#srv-price');

    expect(nameInput).not.toBeNull();
    expect(priceInput).not.toBeNull();

    nameInput!.value = 'Troca de Conector';
    nameInput!.dispatchEvent(new Event('input', { bubbles: true }));

    if (codeInput) {
      codeInput.value = 'SRV-CON';
      codeInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    priceInput!.value = '120';
    priceInput!.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/services');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({
      name: 'Troca de Conector',
      code: 'SRV-CON',
      price: 120,
      active: true,
    });

    expect(wrapper.text()).toContain('Troca de Conector');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    wrapper.unmount();
  });
});

