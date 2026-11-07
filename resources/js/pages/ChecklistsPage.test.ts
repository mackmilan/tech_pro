import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChecklistsPage from './ChecklistsPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('ChecklistsPage', () => {
  it('lists checklist items and creates a new one through modal', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'chk-1',
              name: 'Touch Screen',
              category: 'Tela e Toque',
              description: 'Testar toques em todas as áreas',
              stage: 'both',
              is_required: true,
              sort_order: 1,
              active: true,
              service: null,
            },
          ],
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ data: [] }), // services
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          id: 'chk-2',
          name: 'Câmera Frontal',
          category: 'Câmeras',
          description: 'Testar sensor Face ID e selfie',
          stage: 'entry',
          is_required: false,
          sort_order: 2,
          active: true,
          service: null,
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [
            {
              id: 'chk-1',
              name: 'Touch Screen',
              category: 'Tela e Toque',
              description: 'Testar toques em todas as áreas',
              stage: 'both',
              is_required: true,
              sort_order: 1,
              active: true,
              service: null,
            },
            {
              id: 'chk-2',
              name: 'Câmera Frontal',
              category: 'Câmeras',
              description: 'Testar sensor Face ID e selfie',
              stage: 'entry',
              is_required: false,
              sort_order: 2,
              active: true,
              service: null,
            },
          ],
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ data: [] }), // services
      });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ChecklistsPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Checklist para Execução de Serviços');
    expect(wrapper.text()).toContain('Touch Screen');
    expect(wrapper.text()).toContain('Tela e Toque');

    const newBtn = wrapper.findAll('button').find((b) => b.text().includes('Novo item de checklist'));
    expect(newBtn).toBeDefined();
    await newBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Novo item de checklist');

    const nameInput = document.body.querySelector<HTMLInputElement>('#form-chk-name');
    expect(nameInput).not.toBeNull();
    nameInput!.value = 'Câmera Frontal';
    nameInput!.dispatchEvent(new Event('input', { bubbles: true }));

    const catInput = document.body.querySelector<HTMLInputElement>('#form-chk-cat');
    if (catInput) {
      catInput.value = 'Câmeras';
      catInput!.dispatchEvent(new Event('input', { bubbles: true }));
    }

    await flushPromises();

    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[2][0]).toBe('/api/v1/service-checklists');
    expect(fetch.mock.calls[2][1]).toMatchObject({ method: 'POST' });
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toMatchObject({
      name: 'Câmera Frontal',
      category: 'Câmeras',
      stage: 'both',
      active: true,
    });

    expect(wrapper.text()).toContain('Câmera Frontal');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    wrapper.unmount();
  });
});

