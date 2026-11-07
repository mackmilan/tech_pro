import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ServiceOrderFormPage from './ServiceOrderFormPage.vue';
import ServiceOrdersPage from './ServiceOrdersPage.vue';

const branch = { id: 'branch-1', name: 'Matriz', code: 'MTZ-01' };
const customer = { id: 'customer-1', name: 'Carlos Alberto', document: '123.456.789-00', phone: '(11) 98765-4321' };
const product = { id: 'prod-1', sku: 'TELA-IP13', name: 'Tela iPhone 13 OLED', sale_price: '450.00' };

const existingOrder = {
  id: 'order-1',
  branch_id: 'branch-1',
  customer_id: 'customer-1',
  technician_id: null,
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
  notes: 'Observações cliente',
  warranty_terms: 'Garantia de 90 dias',
  items: [
    {
      id: 'item-1',
      type: 'product',
      product_id: 'prod-1',
      description: 'Tela iPhone 13 OLED',
      quantity: 1,
      unit_price: '450.00',
      discount: '0.00',
      total: '450.00',
      notes: null,
    },
  ],
};

const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('ServiceOrderFormPage', () => {
  it('creates a new service order with items and calculations', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/customers?per_page=200') return json({ data: [customer] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [{ id: 'tech-1', name: 'Lucas Silva', specialty: 'Apple' }] });
      if (url === '/api/v1/products?per_page=200&active=1') return json({ data: [product] });
      if (url === '/api/v1/services?per_page=200&active=1') return json({ data: [{ id: 'srv-1', name: 'Troca de conector', code: 'CON-01', price: '120.00', estimated_minutes: 45, warranty_days: 90 }] });
      if (url === '/api/v1/service-checklists?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/service-orders' && init?.method === 'POST') {
        return json({ ...existingOrder, id: 'order-created' }, 201);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/crm/ordens-de-servico', component: ServiceOrdersPage },
        { path: '/crm/ordens-de-servico/nova', component: ServiceOrderFormPage },
      ],
    });

    await router.push('/crm/ordens-de-servico/nova');
    await router.isReady();

    const wrapper = mount(ServiceOrderFormPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Nova Ordem de Serviço');
    expect(wrapper.findAll('select')).toHaveLength(0);

    // Select customer
    const customerCombobox = wrapper.get('#so-customer');
    await customerCombobox.trigger('focus');
    await customerCombobox.setValue('Carlos');
    const customerOptions = wrapper.findAll('#so-customer-listbox [role="option"]');
    expect(customerOptions.length).toBeGreaterThan(0);
    await customerOptions[0].trigger('click');
    await flushPromises();

    // Fill device & defect
    await wrapper.get('#so-device-model').setValue('iPhone 13 128GB');
    await wrapper.get('#so-defect-reported').setValue('Display quebrado');

    // Add item
    const addServiceBtn = wrapper.findAll('button').find((b) => b.text().includes('Adicionar Serviço'));
    expect(addServiceBtn).toBeDefined();
    await addServiceBtn!.trigger('click');
    await flushPromises();

    await wrapper.get('#item-desc-0').setValue('Mão de obra troca de tela');
    await wrapper.get('#item-price-0').setValue('150');

    // Submit form
    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-orders',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"device_model":"iPhone 13 128GB"'),
      })
    );
  });

  it('loads and edits an existing service order', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/customers?per_page=200') return json({ data: [customer] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [{ id: 'tech-1', name: 'Lucas Silva', specialty: 'Apple' }] });
      if (url === '/api/v1/products?per_page=200&active=1') return json({ data: [product] });
      if (url === '/api/v1/services?per_page=200&active=1') return json({ data: [{ id: 'srv-1', name: 'Troca de conector', code: 'CON-01', price: '120.00', estimated_minutes: 45, warranty_days: 90 }] });
      if (url === '/api/v1/service-checklists?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/service-orders/order-1') return json(existingOrder);
      if (url === '/api/v1/service-orders/order-1' && init?.method === 'PUT') {
        return json(existingOrder, 200);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/crm/ordens-de-servico', component: ServiceOrdersPage },
        { path: '/crm/ordens-de-servico/:id/editar', component: ServiceOrderFormPage },
      ],
    });

    await router.push('/crm/ordens-de-servico/order-1/editar');
    await router.isReady();

    const wrapper = mount(ServiceOrderFormPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    expect(wrapper.get('h1').text()).toContain('Editar Ordem de Serviço OS-202611-0001');
    expect((wrapper.get('#so-device-model').element as HTMLInputElement).value).toBe('iPhone 13 128GB');
    expect((wrapper.get('#so-defect-reported').element as HTMLTextAreaElement).value).toBe('Display quebrado');

    // Update solution
    await wrapper.get('#so-solution-applied').setValue('Tela trocada com sucesso.');

    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-orders/order-1',
      expect.objectContaining({
        method: 'PUT',
        body: expect.stringContaining('"solution_applied":"Tela trocada com sucesso."'),
      })
    );
  });

  it('uses the full available width for the service order form', async () => {
    const fetch = vi.fn(async () => json({ data: [] }));
    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico/nova', component: ServiceOrderFormPage }],
    });
    await router.push('/crm/ordens-de-servico/nova');
    await router.isReady();

    const wrapper = mount(ServiceOrderFormPage, {
      global: { plugins: [router] },
    });
    await flushPromises();

    const page = wrapper.get('section');
    expect(page.classes()).toContain('w-full');
    expect(page.classes().some((className) => className.startsWith('max-w-'))).toBe(false);
  });

  it('opens quick customer registration modal, creates customer and auto-selects it in the order', async () => {
    const newCustomer = {
      id: 'customer-new-1',
      name: 'Mariana Lima',
      phone: '(11) 99999-8888',
      document: '987.654.321-00',
      email: 'mariana@exemplo.com',
      source: 'WhatsApp',
      notes: 'Cliente prioritário',
    };

    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/customers?per_page=200') return json({ data: [customer] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [{ id: 'tech-1', name: 'Lucas Silva', specialty: 'Apple' }] });
      if (url === '/api/v1/products?per_page=200&active=1') return json({ data: [product] });
      if (url === '/api/v1/services?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/service-checklists?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/customers' && init?.method === 'POST') {
        return json(newCustomer, 201);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico/nova', component: ServiceOrderFormPage }],
    });

    await router.push('/crm/ordens-de-servico/nova');
    await router.isReady();

    const wrapper = mount(ServiceOrderFormPage, {
      attachTo: document.body,
      global: { plugins: [router] },
    });
    await flushPromises();

    // Check "+ Novo cliente" button exists
    const quickCustomerBtn = wrapper.get('#btn-quick-customer');
    expect(quickCustomerBtn.text()).toContain('Novo cliente');

    // Click button to open modal
    await quickCustomerBtn.trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain('Cadastro Rápido de Cliente');

    // Fill customer fields
    const nameInput = document.body.querySelector<HTMLInputElement>('#quick-cust-name')!;
    nameInput.value = 'Mariana Lima';
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));

    const phoneInput = document.body.querySelector<HTMLInputElement>('#quick-cust-phone')!;
    phoneInput.value = '(11) 99999-8888';
    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));

    const docInput = document.body.querySelector<HTMLInputElement>('#quick-cust-document')!;
    docInput.value = '987.654.321-00';
    docInput.dispatchEvent(new Event('input', { bubbles: true }));

    const emailInput = document.body.querySelector<HTMLInputElement>('#quick-cust-email')!;
    emailInput.value = 'mariana@exemplo.com';
    emailInput.dispatchEvent(new Event('input', { bubbles: true }));

    await flushPromises();

    // Submit quick customer modal form
    const modalForm = dialog!.querySelector('form')!;
    modalForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    // Verify fetch call to create customer
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/customers',
      expect.objectContaining({
        method: 'POST',
        body: expect.stringContaining('"name":"Mariana Lima"'),
      })
    );

    // Verify modal is closed
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    // Verify the customer is now selected in the combobox input
    const comboboxInput = wrapper.get('#so-customer').element as HTMLInputElement;
    expect(comboboxInput.value).toContain('Mariana Lima');

    wrapper.unmount();
  });

  it('displays checklist photos and allows uploading and deleting a photo for a checklist item', async () => {
    const orderWithChecklistPhotos = {
      ...existingOrder,
      checklists: [
        {
          id: 'checklist-uuid-1',
          service_checklist_id: null,
          item_name: 'Tela frontal',
          category: 'Display',
          stage: 'entry',
          status: 'defect',
          notes: 'Quebrada no canto',
          images: [
            {
              id: 'img-100',
              imageable_id: 'checklist-uuid-1',
              imageable_type: 'App\\Models\\ServiceOrderChecklist',
              url: 'http://localhost/storage/images/screen.png',
              original_name: 'screen.png',
              mime_type: 'image/png',
              size: 1024,
              is_primary: true,
              sort_order: 1,
            },
          ],
        },
      ],
    };

    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/branches?per_page=100') return json({ data: [branch] });
      if (url === '/api/v1/customers?per_page=200') return json({ data: [customer] });
      if (url === '/api/v1/technicians?per_page=100&active=1') return json({ data: [] });
      if (url === '/api/v1/products?per_page=200&active=1') return json({ data: [product] });
      if (url === '/api/v1/services?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/service-checklists?per_page=200&active=1') return json({ data: [] });
      if (url === '/api/v1/service-orders/order-1') return json(orderWithChecklistPhotos);
      if (url === '/api/v1/service-order-checklists/checklist-uuid-1/images' && init?.method === 'POST') {
        return json({
          id: 'img-101',
          imageable_id: 'checklist-uuid-1',
          imageable_type: 'App\\Models\\ServiceOrderChecklist',
          url: 'http://localhost/storage/images/new_photo.png',
          original_name: 'new_photo.png',
          mime_type: 'image/png',
          size: 2048,
          is_primary: false,
          sort_order: 2,
        }, 201);
      }
      if (url === '/api/v1/images/img-100' && init?.method === 'DELETE') {
        return json(null, 204);
      }
      throw new Error(`Unexpected request ${url}`);
    });

    vi.stubGlobal('fetch', fetch);

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/crm/ordens-de-servico/:id/editar', component: ServiceOrderFormPage }],
    });

    await router.push('/crm/ordens-de-servico/order-1/editar');
    await router.isReady();

    const wrapper = mount(ServiceOrderFormPage, {
      attachTo: document.body,
      global: { plugins: [router] },
    });
    await flushPromises();

    // Check existing checklist photo thumbnail
    const existingPhotoImg = wrapper.find('img[alt="screen.png"]');
    expect(existingPhotoImg.exists()).toBe(true);

    // Upload a new photo
    const fileInput = wrapper.get('#chk-photo-checklist-uuid-1');
    const fakeFile = new File(['fake-content'], 'new_photo.png', { type: 'image/png' });
    Object.defineProperty(fileInput.element, 'files', {
      value: [fakeFile],
      configurable: true,
    });
    await fileInput.trigger('change');
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/service-order-checklists/checklist-uuid-1/images',
      expect.objectContaining({ method: 'POST' })
    );

    // Verify newly uploaded image is displayed
    expect(wrapper.find('img[alt="new_photo.png"]').exists()).toBe(true);

    // Delete the first photo
    const deleteBtn = wrapper.get('button[aria-label="Excluir imagem screen.png"]');
    await deleteBtn.trigger('click');
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/images/img-100',
      expect.objectContaining({ method: 'DELETE' })
    );
    expect(wrapper.find('img[alt="screen.png"]').exists()).toBe(false);

    wrapper.unmount();
  });
});

