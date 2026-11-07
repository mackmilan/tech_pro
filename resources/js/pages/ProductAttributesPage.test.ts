import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProductAttributesPage from './ProductAttributesPage.vue';

const selectComboboxOption = async (selector: string, query: string, option: string) => {
  const input = document.body.querySelector<HTMLInputElement>(selector)!;
  input.focus();
  input.value = query;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  await flushPromises();
  const match = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find((item) => item.textContent?.trim().includes(option));
  expect(match, `combobox option not found: ${option}`).toBeTruthy();
  match!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  await flushPromises();
};

afterEach(() => vi.unstubAllGlobals());

describe('ProductAttributesPage', () => {
  it('renders attributes from the paginated API response envelope', async () => {
    const attribute = {
      id: 'attribute-1', name: 'Armazenamento', entity_type: 'produto', type: 'selecao_unica', options: ['128 GB'],
      required: true, differentiate_stock: false, active: true, categories: [],
    };
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: { data: [attribute] } }) }));

    const wrapper = mount(ProductAttributesPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();

    expect(wrapper.text()).toContain('Armazenamento');
    expect(wrapper.text()).not.toContain('Nenhum atributo cadastrado.');
    wrapper.unmount();
  });

  it('loads attributes and categories and creates a single-choice stock attribute', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'category-1', name: 'Celulares', children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({
        id: 'attribute-1', name: 'Armazenamento', entity_type: 'produto', type: 'selecao_unica', options: ['128 GB', '256 GB'], required: true, differentiate_stock: true,
        categories: [{ id: 'category-1', name: 'Celulares' }], active: true,
      }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{
        id: 'attribute-1', name: 'Armazenamento', entity_type: 'produto', type: 'selecao_unica', options: ['128 GB', '256 GB'], required: true, differentiate_stock: true,
        categories: [{ id: 'category-1', name: 'Celulares' }], active: true,
      }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{
        id: 'attribute-1', name: 'Armazenamento', entity_type: 'produto', type: 'selecao_unica', options: ['128 GB', '256 GB'], required: true, differentiate_stock: true,
        categories: [{ id: 'category-1', name: 'Celulares' }], active: true,
      }] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(ProductAttributesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.findAll('input[role="combobox"]').map((input) => input.attributes('id'))).toEqual(['attribute-entity-filter']);
    expect(wrapper.text()).toContain('Nenhum atributo cadastrado.');
    await wrapper.get('button[aria-label="Novo atributo"]').trigger('click');
    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog?.textContent).toContain('Novo atributo');

    const setValue = (selector: string, value: string) => {
      const input = document.body.querySelector<HTMLInputElement>(selector)!;
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    };
    setValue('#attribute-name', 'Armazenamento');
    await selectComboboxOption('#attribute-type', 'unica', 'Seleção única');
    await flushPromises();
    setValue('#attribute-options', '128 GB\n256 GB');
    const category = document.body.querySelector<HTMLInputElement>('#category-category-1')!;
    category.checked = true;
    category.dispatchEvent(new Event('change', { bubbles: true }));
    const flags = document.body.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    const requiredCheckbox = document.body.querySelector<HTMLInputElement>('input[type="checkbox"]:not([id^="category-"])')!;
    requiredCheckbox.checked = true;
    requiredCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
    const stockCheckbox = [...flags].find((checkbox) => checkbox.parentElement?.textContent?.includes('Diferenciar estoque'))!;
    stockCheckbox.checked = true;
    stockCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();

    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
    expect(fetch.mock.calls[0][0]).toBe('/api/v1/categories');
    expect(fetch.mock.calls[1][0]).toBe('/api/v1/product-attributes?entity_type=produto');
    expect(fetch.mock.calls[2][0]).toBe('/api/v1/product-attributes');
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({
      entity_type: 'produto', name: 'Armazenamento', type: 'selecao_unica', options: ['128 GB', '256 GB'], required: true,
      differentiate_stock: true, per_unit: false, category_ids: ['category-1'], active: true,
    });
    expect(wrapper.text()).toContain('Armazenamento');
    wrapper.unmount();
  });

  it('creates per-unit tracking fields for stock attributes', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'category-1', name: 'Celulares', children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({
        id: 'imei-id', name: 'IMEI', entity_type: 'estoque', type: 'texto', options: null, required: true,
        differentiate_stock: false, per_unit: true, active: true, categories: [],
      }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(ProductAttributesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();
    await wrapper.get('button[aria-label="Novo atributo"]').trigger('click');
    await selectComboboxOption('#attribute-entity-type', 'Estoque', 'Estoque');
    const perUnit = [...document.body.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')]
      .find((checkbox) => checkbox.parentElement?.textContent?.includes('Informar um valor para cada unidade recebida'))!;
    perUnit.checked = true;
    perUnit.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
    expect(document.body.querySelector('input[type="checkbox"]:not([id^="category-"])')?.checked).toBe(false);
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
    expect(JSON.parse(String(fetch.mock.calls[2][1]?.body))).toMatchObject({ entity_type: 'estoque', per_unit: true, differentiate_stock: false });
    wrapper.unmount();
  });

  it('filters definitions by entity type and submits the selected type when creating one', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'category-1', name: 'Celulares', children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{
        id: 'product-attribute', name: 'Garantia', entity_type: 'produto', type: 'texto', options: null,
        required: false, differentiate_stock: false, active: true, categories: [],
      }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'category-1', name: 'Celulares', children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{
        id: 'stock-attribute', name: 'Material', entity_type: 'estoque', type: 'texto', options: null,
        required: false, differentiate_stock: false, per_unit: false, active: true, categories: [{ id: 'category-1', name: 'Celulares' }],
      }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'stock-attribute-2', name: 'Acabamento', entity_type: 'estoque', type: 'texto', options: null, required: false, differentiate_stock: false, active: true, categories: [{ id: 'category-1', name: 'Celulares' }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'category-1', name: 'Celulares', children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{
        id: 'stock-attribute-2', name: 'Acabamento', entity_type: 'estoque', type: 'texto', options: null,
        required: false, differentiate_stock: false, active: true, categories: [{ id: 'category-1', name: 'Celulares' }],
      }] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(ProductAttributesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();

    await selectComboboxOption('#attribute-entity-filter', 'Estoque', 'Estoque');
    expect(fetch.mock.calls[0][0]).toBe('/api/v1/categories');
    expect(fetch.mock.calls[1][0]).toBe('/api/v1/product-attributes?entity_type=produto');
    expect(fetch.mock.calls[2][0]).toBe('/api/v1/categories');
    expect(fetch.mock.calls[3][0]).toBe('/api/v1/product-attributes?entity_type=estoque');
    expect(wrapper.text()).toContain('Material');
    expect(wrapper.text()).toContain('Celulares');
    expect(wrapper.text()).not.toContain('Garantia');

    await wrapper.get('button[aria-label="Novo atributo"]').trigger('click');
    await selectComboboxOption('#attribute-entity-type', 'estoque', 'Estoque');
    const entityType = document.body.querySelector<HTMLInputElement>('#attribute-entity-type')!;
    const category = document.body.querySelector<HTMLInputElement>('#category-category-1')!;
    category.checked = true;
    category.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
    expect(entityType.value).toBe('Estoque');
    document.body.querySelector<HTMLInputElement>('#attribute-name')!.value = 'Acabamento';
    document.body.querySelector<HTMLInputElement>('#attribute-name')!.dispatchEvent(new Event('input', { bubbles: true }));
    document.body.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();

    expect(fetch.mock.calls[4][0]).toBe('/api/v1/product-attributes');
    expect(JSON.parse(fetch.mock.calls[4][1].body)).toMatchObject({ name: 'Acabamento', entity_type: 'estoque', category_ids: ['category-1'] });
    expect(fetch.mock.calls[5][0]).toBe('/api/v1/categories');
    expect(fetch.mock.calls[6][0]).toBe('/api/v1/product-attributes?entity_type=estoque');
    expect(wrapper.text()).toContain('Acabamento');
    wrapper.unmount();
  });

  it('shows configured choices only for selection types', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) }));
    const wrapper = mount(ProductAttributesPage, { global: { stubs: { RouterLink: true } }, attachTo: document.body });
    await flushPromises();
    await wrapper.get('button[aria-label="Novo atributo"]').trigger('click');
    expect(document.body.querySelector('#attribute-options')).toBeNull();
    await selectComboboxOption('#attribute-entity-type', 'Produto', 'Produto');
    await selectComboboxOption('#attribute-type', 'multipla', 'Seleção múltipla');
    await flushPromises();
    expect(document.body.querySelector('#attribute-options')).not.toBeNull();
    wrapper.unmount();
  });
});
