import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ServiceOrderStatusRulesPage from './ServiceOrderStatusRulesPage.vue';

const sampleRule = {
  id: 'rule-open',
  status: 'open',
  name: 'Aberta',
  description: 'Ordem cadastrada',
  color: 'slate',
  allow_view: true,
  allow_edit: true,
  allow_execute: true,
  allow_cancel: true,
  allow_print: true,
  allowed_transitions: ['in_analysis', 'approved', 'in_progress', 'cancelled'],
  active: true,
};

const completedRule = {
  id: 'rule-completed',
  status: 'completed',
  name: 'Concluída',
  description: 'Reparo finalizado',
  color: 'emerald',
  allow_view: true,
  allow_edit: false,
  allow_execute: false,
  allow_cancel: false,
  allow_print: true,
  allowed_transitions: ['delivered'],
  active: true,
};

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('ServiceOrderStatusRulesPage', () => {
  it('renders rules list, ensures no native selects, and creates a new rule', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-order-status-rules?') && (!init?.method || init.method === 'GET')) {
        return {
          ok: true,
          json: async () => ({ data: [sampleRule, completedRule] }),
        } as Response;
      }
      if (url === '/api/v1/service-order-status-rules' && init?.method === 'POST') {
        const body = JSON.parse(String(init.body));
        return {
          ok: true,
          status: 201,
          json: async () => ({ id: 'rule-new', ...body }),
        } as Response;
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ServiceOrderStatusRulesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    // Verify header and table items
    expect(wrapper.get('h1').text()).toBe('Máquina de Estados de Ordens de Serviço');
    expect(wrapper.text()).toContain('Aberta');
    expect(wrapper.text()).toContain('Concluída');
    expect(wrapper.text()).toContain('Visualizar');
    expect(wrapper.text()).toContain('Editar');
    expect(wrapper.text()).toContain('Executar');
    expect(wrapper.text()).toContain('Cancelar');

    // Rule compliance: absolutely no native <select> elements
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Open create modal
    const createBtn = wrapper.findAll('button').find((b) => b.text().includes('Nova regra de status'));
    expect(createBtn).toBeDefined();
    await createBtn!.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Nova Regra de Status da OS');

    // Fill form
    const nameInput = document.body.querySelector<HTMLInputElement>('#rule-name')!;
    expect(nameInput).toBeDefined();
    nameInput.value = 'Em Triagem Inicial';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    const descInput = document.body.querySelector<HTMLTextAreaElement>('#rule-description')!;
    descInput.value = 'Avaliação rápida de entrada';
    descInput.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-order-status-rules',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"name":"Em Triagem Inicial"'),
      })
    );

    wrapper.unmount();
  });

  it('opens edit modal and updates existing rule', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-order-status-rules?') && (!init?.method || init.method === 'GET')) {
        return {
          ok: true,
          json: async () => ({ data: [sampleRule] }),
        } as Response;
      }
      if (url === '/api/v1/service-order-status-rules/rule-open' && init?.method === 'PUT') {
        const body = JSON.parse(String(init.body));
        return {
          ok: true,
          json: async () => ({ ...sampleRule, ...body }),
        } as Response;
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ServiceOrderStatusRulesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    const editBtn = wrapper.get('button[aria-label="Editar regra"]');
    await editBtn.trigger('click');
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Editar Regra de Status da OS');

    const nameInput = document.body.querySelector<HTMLInputElement>('#rule-name')!;
    nameInput.value = 'Aberta e Triada';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();

    const form = document.body.querySelector('[role="dialog"] form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-order-status-rules/rule-open',
      expect.objectContaining({
        method: 'PUT',
        body: expect.stringContaining('"name":"Aberta e Triada"'),
      })
    );

    wrapper.unmount();
  });

  it('deletes a rule through confirmation dialog', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-order-status-rules?') && (!init?.method || init.method === 'GET')) {
        return {
          ok: true,
          json: async () => ({ data: [sampleRule] }),
        } as Response;
      }
      if (url === '/api/v1/service-order-status-rules/rule-open' && init?.method === 'DELETE') {
        return { ok: true, status: 204 } as Response;
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ServiceOrderStatusRulesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    const deleteBtn = wrapper.get('button[aria-label="Excluir regra"]');
    await deleteBtn.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Excluir Regra de Status');

    const confirmBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Excluir regra');
    expect(confirmBtn).toBeDefined();
    confirmBtn!.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-order-status-rules/rule-open',
      expect.objectContaining({ method: 'DELETE' })
    );

    wrapper.unmount();
  });

  it('restores default rules through confirmation dialog', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-order-status-rules?') && (!init?.method || init.method === 'GET')) {
        return {
          ok: true,
          json: async () => ({ data: [sampleRule] }),
        } as Response;
      }
      if (url === '/api/v1/service-order-status-rules/seed-defaults' && init?.method === 'POST') {
        return {
          ok: true,
          json: async () => ({ message: 'Regras de status restauradas com sucesso.', data: [sampleRule, completedRule] }),
        } as Response;
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const wrapper = mount(ServiceOrderStatusRulesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    });
    await flushPromises();

    const restoreBtn = wrapper.findAll('button').find((b) => b.text().includes('Restaurar padrões'));
    expect(restoreBtn).toBeDefined();
    await restoreBtn!.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Restaurar Regras Padrão da Máquina de Estados');

    const confirmBtn = Array.from(document.body.querySelectorAll('[role="dialog"] button')).find((b) => b.textContent?.trim() === 'Restaurar padrões');
    expect(confirmBtn).toBeDefined();
    confirmBtn!.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-order-status-rules/seed-defaults',
      expect.objectContaining({ method: 'POST' })
    );

    wrapper.unmount();
  });
});
