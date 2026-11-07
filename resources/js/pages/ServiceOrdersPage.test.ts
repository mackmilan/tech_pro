import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ServiceOrdersPage from './ServiceOrdersPage.vue';

const branch = { id: 'branch-1', name: 'Matriz', code: 'MTZ-01' };
const customer = { id: 'customer-1', name: 'Carlos Alberto', document: '123.456.789-00', phone: '(11) 98765-4321', email: 'carlos@example.com' };
const technician = { id: 'tech-1', name: 'Técnico Especialista' };

const order = {
  id: 'order-1',
  branch_id: 'branch-1',
  customer_id: 'customer-1',
  technician_id: 'tech-1',
  number: 'OS-202611-0001',
  status: 'in_progress',
  priority: 'high',
  device_brand: 'Apple',
  device_model: 'iPhone 13 128GB',
  device_imei_serial: '356789012345678',
  device_color: 'Azul',
  device_password: '123',
  device_condition: 'Tela trincada',
  accessories: 'Capa',
  defect_reported: 'Display quebrado',
  defect_diagnosed: 'Substituição da tela',
  solution_applied: null,
  technical_notes: 'Bateria a 82%',
  opened_at: '2026-11-06T10:00:00.000000Z',
  expected_at: '2026-11-07T18:00:00.000000Z',
  completed_at: null,
  delivered_at: null,
  services_amount: '150.00',
  products_amount: '450.00',
  discount: '20.00',
  total: '580.00',
  notes: 'Cliente retira amanhã',
  warranty_terms: 'Garantia de 90 dias',
  branch,
  customer,
  technician,
  items: [
    {
      id: 'item-1',
      type: 'product',
      description: 'Tela iPhone 13 OLED',
      quantity: 1,
      unit_price: '450.00',
      discount: '0.00',
      total: '450.00',
      notes: null,
    },
    {
      id: 'item-2',
      type: 'service',
      description: 'Mão de obra de troca',
      quantity: 1,
      unit_price: '150.00',
      discount: '0.00',
      total: '150.00',
      notes: null,
    },
  ],
};

const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('ServiceOrdersPage', () => {
  it('renders list of service orders, filters, opens details modal and cancels order', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-orders?')) {
        return json({ data: [order], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') {
        return json({ data: [branch] });
      }
      if (url === '/api/v1/technicians?per_page=100&active=1') {
        return json({ data: [technician] });
      }
      if (url === '/api/v1/service-orders/order-1/cancel' && init?.method === 'POST') {
        return json({ ...order, status: 'cancelled' }, 200);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/crm/ordens-de-servico', component: ServiceOrdersPage },
        { path: '/crm/ordens-de-servico/nova', component: { template: '<div>Nova OS</div>' } },
        { path: '/crm/ordens-de-servico/:id/editar', component: { template: '<div>Editar OS</div>' } },
      ],
    });

    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    // Verify title and headers
    expect(wrapper.get('h1').text()).toBe('Ordens de Serviço');
    expect(wrapper.text()).toContain('OS-202611-0001');
    expect(wrapper.text()).toContain('Carlos Alberto');
    expect(wrapper.text()).toContain('Apple iPhone 13 128GB');
    expect(wrapper.text()).toContain('Em andamento');
    expect(wrapper.text()).toContain('Alta');

    // Filter controls: all must be SearchableCombobox, no native select
    expect(wrapper.findAll('select')).toHaveLength(0);
    const comboboxes = wrapper.findAll('input[role="combobox"]');
    expect(comboboxes.map((c) => c.attributes('id'))).toEqual([
      'filter-branch',
      'filter-status',
      'filter-priority',
    ]);

    // Open view modal
    const viewButton = wrapper.get('button[aria-label="Visualizar detalhes"]');
    await viewButton.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Ordem de Serviço OS-202611-0001');
    expect(document.body.textContent).toContain('Defeito Relatado');
    expect(document.body.textContent).toContain('Display quebrado');
    expect(document.body.textContent).toContain('Tela iPhone 13 OLED');

    // Close view modal
    const closeBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Fechar');
    expect(closeBtn).toBeDefined();
    closeBtn!.click();
    await flushPromises();

    // Verify delete action does not exist
    expect(wrapper.find('button[aria-label="Excluir ordem de serviço"]').exists()).toBe(false);

    // Prompt cancel
    const cancelButton = wrapper.get('button[aria-label="Cancelar ordem de serviço"]');
    await cancelButton.trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('Cancelar ordem de serviço');
    expect(document.body.textContent).toContain('Tem certeza que deseja cancelar a ordem de serviço OS-202611-0001?');

    // Confirm cancel
    const confirmButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Cancelar ordem de serviço');
    expect(confirmButton).toBeDefined();
    confirmButton!.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-orders/order-1/cancel',
      expect.objectContaining({ method: 'POST' })
    );

    wrapper.unmount();
  });

  it('hides edit, execute and cancel actions when order is delivered or cancelled', async () => {
    const deliveredOrder = { ...order, id: 'order-deliv', number: 'OS-DELIV', status: 'delivered' };
    const cancelledOrder = { ...order, id: 'order-canc', number: 'OS-CANC', status: 'cancelled' };

    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-orders?')) {
        return json({ data: [deliveredOrder, cancelledOrder], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [technician] });
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico', component: ServiceOrdersPage }],
    });

    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.find('button[aria-label="Excluir ordem de serviço"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Cancelar ordem de serviço"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Executar ordem de serviço"]').exists()).toBe(false);
    expect(wrapper.find('a[aria-label="Editar ordem de serviço"]').exists()).toBe(false);

    wrapper.unmount();
  });

  it('uses the full available width for the service orders listing', async () => {
    const fetch = vi.fn(async () => json({ data: [] }));
    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico', component: ServiceOrdersPage }],
    });
    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    const page = wrapper.get('section');
    expect(page.classes()).toContain('w-full');
    expect(page.classes().some((className) => className.startsWith('max-w-'))).toBe(false);
    wrapper.unmount();
  });

  it('opens execution modal from action button, updates execution progress and saves', async () => {
    const updatedOrder = {
      ...order,
      status: 'in_progress',
      defect_diagnosed: 'Conector com oxidação',
      solution_applied: 'Limpeza ultrassônica realizada',
      technical_notes: 'Bateria ok',
    };

    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-orders?')) {
        return json({ data: [order], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') {
        return json({ data: [branch] });
      }
      if (url === '/api/v1/technicians?per_page=100&active=1') {
        return json({ data: [technician] });
      }
      if (url === '/api/v1/service-orders/order-1/execute' && init?.method === 'POST') {
        return json(updatedOrder, 200);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico', component: ServiceOrdersPage }],
    });
    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    // Find and click execute button on the row
    const executeButton = wrapper.get('button[aria-label="Executar ordem de serviço"]');
    await executeButton.trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain('Executar Ordem de Serviço OS-202611-0001');
    expect(dialog?.textContent).toContain('Apple iPhone 13 128GB');
    expect(dialog?.textContent).toContain('Display quebrado');

    // Fill in execution fields
    const diagnosisInput = document.body.querySelector<HTMLTextAreaElement>('#exec-diagnosis')!;
    diagnosisInput.value = 'Conector com oxidação';
    diagnosisInput.dispatchEvent(new Event('input', { bubbles: true }));

    const solutionInput = document.body.querySelector<HTMLTextAreaElement>('#exec-solution')!;
    solutionInput.value = 'Limpeza ultrassônica realizada';
    solutionInput.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    // Click save execution button
    const saveBtn = document.body.querySelector<HTMLButtonElement>('#btn-save-execution')!;
    saveBtn.click();
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-orders/order-1/execute',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"defect_diagnosed":"Conector com oxidação"'),
      })
    );

    // Modal is closed
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    wrapper.unmount();
  });

  it('respects dynamic state machine rules for action visibility based on order status', async () => {
    // Custom rule: in_progress allows view and execute, but disallows edit and cancel
    const customRule = {
      status: 'in_progress',
      allow_view: true,
      allow_edit: false,
      allow_execute: true,
      allow_cancel: false,
      allow_print: true,
      allowed_transitions: ['completed'],
    };

    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-orders?')) {
        return json({ data: [order], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [technician] });
      if (url.startsWith('/api/v1/service-order-status-rules?')) {
        return json({ data: [customRule] });
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico', component: ServiceOrdersPage }],
    });

    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    // With the state machine rule:
    // View and Execute should exist
    expect(wrapper.find('button[aria-label="Visualizar detalhes"]').exists()).toBe(true);
    expect(wrapper.find('button[aria-label="Executar ordem de serviço"]').exists()).toBe(true);
    // Edit and Cancel should be HIDDEN according to the state machine rule!
    expect(wrapper.find('a[aria-label="Editar ordem de serviço"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Cancelar ordem de serviço"]').exists()).toBe(false);

    wrapper.unmount();
  });

  it('renders checklist items with photos in details modal and execution modal', async () => {
    const orderWithChecklistPhotos = {
      ...order,
      checklists: [
        {
          id: 'chk-50',
          service_checklist_id: null,
          item_name: 'Câmera Traseira',
          category: 'Câmera',
          stage: 'entry',
          status: 'defect',
          notes: 'Lente riscada',
          images: [
            {
              id: 'img-501',
              imageable_id: 'chk-50',
              imageable_type: 'App\\Models\\ServiceOrderChecklist',
              url: 'http://localhost/storage/images/camera_defect.jpg',
              original_name: 'camera_defect.jpg',
              mime_type: 'image/jpeg',
              size: 2048,
              is_primary: true,
              sort_order: 1,
            },
          ],
        },
      ],
    };

    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.startsWith('/api/v1/service-orders?')) {
        return json({ data: [orderWithChecklistPhotos], current_page: 1, last_page: 1 });
      }
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [technician] });
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico', component: ServiceOrdersPage }],
    });

    await router.push('/crm/ordens-de-servico');
    await router.isReady();

    const wrapper = mount(ServiceOrdersPage, {
      global: { plugins: [router] },
      attachTo: document.body,
    });
    await flushPromises();

    // 1. Open view modal
    const viewBtn = wrapper.get('button[aria-label="Visualizar detalhes"]');
    await viewBtn.trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain('Checklist Técnico e Fotos');
    expect(dialog?.textContent).toContain('Câmera Traseira');
    expect(dialog?.querySelector('img[alt="camera_defect.jpg"]')).not.toBeNull();

    // Close view modal
    const closeBtn = dialog?.querySelector('button[aria-label="Fechar"]') as HTMLButtonElement | null;
    closeBtn?.click();
    await flushPromises();

    // 2. Open execute modal
    const executeBtn = wrapper.get('button[aria-label="Executar ordem de serviço"]');
    await executeBtn.trigger('click');
    await flushPromises();

    const execDialog = document.body.querySelector('[role="dialog"]');
    expect(execDialog).not.toBeNull();
    expect(execDialog?.textContent).toContain('Câmera Traseira');
    expect(execDialog?.querySelector('img[alt="camera_defect.jpg"]')).not.toBeNull();
    expect(execDialog?.textContent).toContain('+ Foto');

    wrapper.unmount();
  });
});
