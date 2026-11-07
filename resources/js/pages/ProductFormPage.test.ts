import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ProductFormPage from './ProductFormPage.vue';
import ProductsPage from './ProductsPage.vue';

const categories = [{ id: 'cat-1', name: 'Celulares', parent_id: null, children: [] }];
const brands = [{ id: 'brand-1', name: 'Acme', active: true }];
const product = {
  id: 'product-1', sku: 'PHONE-1', name: 'Telefone A', category: 'Celulares', category_id: 'cat-1',
  brand: 'Acme', brand_id: 'brand-1', description: 'Descrição', sale_price: '150.00', active: true,
};
const attribute = { id: 'attr-1', name: 'Cor', entity_type: 'produto', type: 'selecao_unica', options: ['Preto', 'Azul'], required: true, differentiate_stock: false, categories: [{ id: 'cat-1' }] };
const routes = [
  { path: '/erp/produtos', component: ProductsPage },
  { path: '/erp/produtos/novo', component: ProductFormPage },
  { path: '/erp/produtos/:id/editar', component: ProductFormPage },
];
const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;
afterEach(() => vi.unstubAllGlobals());

describe('ProductFormPage', () => {
  it('navigates from the product list to a dedicated create page and saves product plus attributes', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/products?')) return json({ data: [product], current_page: 1, last_page: 1 });
      if (url === '/api/v1/categories') return json({ data: categories });
      if (url === '/api/v1/brands?per_page=100') return json({ data: brands });
      if (url === '/api/v1/product-conditions?per_page=100') return json({ data: [{ id: 'condition-1', name: 'Novo', active: true }] });
      if (url === '/api/v1/product-attributes?entity_type=produto&all=true') return json({ data: [attribute] });
      if (url === '/api/v1/products' && init?.method === 'POST') return json(product, 201);
      if (url === '/api/v1/products/product-1/attributes' && init?.method === 'PUT') return json({ data: [] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/erp/produtos');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    const link = wrapper.find('a[href="/erp/produtos/novo"]');
    expect(link.text()).toContain('Novo produto');
    await link.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe('/erp/produtos/novo');
    expect(wrapper.get('h1').text()).toBe('Novo produto');
    expect(wrapper.findAll('select')).toHaveLength(0);
    expect(wrapper.findAll('input[role="combobox"]').map((input) => input.attributes('id'))).toEqual(['product-category', 'product-brand']);
    expect(wrapper.find('#product-condition').exists()).toBe(false);
    expect(wrapper.find('#product-cost-price').exists()).toBe(false);
    await wrapper.get('#product-sku').setValue('PHONE-1');
    await wrapper.get('#product-name').setValue('Telefone A');
    const category = wrapper.get('#product-category');
    await category.trigger('focus');
    await category.setValue('Celulares');
    await wrapper.get('[role="option"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="product-attribute-attr-1"]').exists()).toBe(true);
    const brand = wrapper.get('#product-brand');
    await brand.trigger('focus');
    await brand.setValue('Acme');
    await wrapper.get('[role="option"]').trigger('click');
    await wrapper.get('#product-sale-price').setValue('150');
    const color = wrapper.get('[data-testid="product-attribute-attr-1"]');
    await color.trigger('focus');
    await color.setValue('Azul');
    await wrapper.get('[role="option"]').trigger('click');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const createCall = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/products' && init?.method === 'POST');
    const payload = JSON.parse(String(createCall?.[1]?.body));
    expect(payload).toMatchObject({ sku: 'PHONE-1', name: 'Telefone A', category_id: 'cat-1', brand_id: 'brand-1', sale_price: 150 });
    expect(payload).not.toHaveProperty('condition_id');
    const attributeCall = fetch.mock.calls.find(([url, init]) => String(url) === '/api/v1/products/product-1/attributes' && init?.method === 'PUT');
    expect(JSON.parse(String(attributeCall?.[1]?.body))).toEqual({ attributes: [{ product_attribute_id: 'attr-1', value: 'Azul' }] });
    expect(router.currentRoute.value.fullPath).toBe('/erp/produtos');
    wrapper.unmount();
  });

  it('renders only active attributes applicable to the product category and uses each configured field type', async () => {
    const nestedCategories = [{ id: 'cat-parent', name: 'Celulares', parent_id: null, children: [{ id: 'cat-child', name: 'Smartphones', parent_id: 'cat-parent', children: [] }] }, { id: 'cat-other', name: 'Acessórios', parent_id: null, children: [] }];
    const definitions = [
      { ...attribute, id: 'attr-parent', name: 'Garantia', type: 'texto', required: false, categories: [{ id: 'cat-parent' }] },
      { ...attribute, id: 'attr-global', name: 'Peso', type: 'numerico', required: false, categories: [] },
      { ...attribute, id: 'attr-single', name: 'Cor', type: 'selecao_unica', categories: [{ id: 'cat-child' }] },
      { ...attribute, id: 'attr-multiple', name: 'Recursos', type: 'selecao_multipla', required: false, categories: [{ id: 'cat-child' }] },
      { ...attribute, id: 'attr-bool', name: 'Resistente à água', type: 'booleano', required: false, categories: [{ id: 'cat-child' }] },
      { ...attribute, id: 'attr-unrelated', name: 'Tamanho', type: 'texto', categories: [{ id: 'cat-other' }] },
      { ...attribute, id: 'attr-inactive', name: 'Legado', type: 'texto', active: false, required: false, categories: [{ id: 'cat-child' }] },
    ];
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url === '/api/v1/categories') return json({ data: nestedCategories });
      if (url === '/api/v1/brands?per_page=100') return json({ data: brands });
      if (url === '/api/v1/products/product-1') return json({ ...product, category_id: 'cat-child' });
      if (url === '/api/v1/product-attributes?entity_type=produto&all=true') return json({ data: definitions });
      if (url === '/api/v1/products/product-1/attributes') return json({ data: [] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/erp/produtos/product-1/editar');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Garantia');
    expect(wrapper.text()).toContain('Peso');
    expect(wrapper.text()).toContain('Cor');
    expect(wrapper.text()).toContain('Recursos');
    expect(wrapper.text()).toContain('Resistente à água');
    expect(wrapper.text()).not.toContain('Tamanho');
    expect(wrapper.text()).not.toContain('Legado');
    expect(wrapper.get('[data-testid="product-attribute-attr-parent"]').attributes('type')).toBe('text');
    expect(wrapper.get('[data-testid="product-attribute-attr-global"]').attributes('type')).toBe('number');
    expect(wrapper.get('[data-testid="product-attribute-attr-single"]').attributes('role')).toBe('combobox');
    expect(wrapper.get('[data-testid="product-attribute-attr-multiple"]').attributes('role')).toBe('combobox');
    await wrapper.get('[data-testid="product-attribute-attr-multiple"]').trigger('focus');
    expect(wrapper.get('#product-attribute-attr-multiple-listbox').attributes('aria-multiselectable')).toBe('true');
    expect(wrapper.get('[data-testid="product-attribute-attr-bool"]').attributes('type')).toBe('checkbox');
    wrapper.unmount();
  });

  it('selects multiple configured values for a product attribute', async () => {
    const multipleAttribute = { ...attribute, id: 'attr-multiple', name: 'Recursos', type: 'selecao_multipla', required: false };
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url === '/api/v1/categories') return json({ data: categories });
      if (url === '/api/v1/brands?per_page=100') return json({ data: brands });
      if (url === '/api/v1/products/product-1') return json(product);
      if (url === '/api/v1/product-attributes?entity_type=produto&all=true') return json({ data: [multipleAttribute] });
      if (url === '/api/v1/products/product-1/attributes') return json({ data: [{ product_attribute_id: 'attr-multiple', value: [] }] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/erp/produtos/product-1/editar');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    const input = wrapper.get('[data-testid="product-attribute-attr-multiple"]');
    await input.trigger('focus');
    await wrapper.get('#product-attribute-attr-multiple-listbox [role="option"]:nth-child(1)').trigger('click');
    await flushPromises();
    expect(wrapper.findAll('button[aria-label^="Remover "]').map((button) => button.attributes('aria-label'))).toEqual(['Remover Preto']);
    await wrapper.get('#product-attribute-attr-multiple-listbox [role="option"]:nth-child(2)').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="product-attribute-attr-multiple"]').element.closest('.relative')?.innerHTML).toContain('Remover');
    expect(wrapper.findAll('button[aria-label^="Remover "]').map((button) => button.attributes('aria-label'))).toEqual(['Remover Preto', 'Remover Azul']);
    wrapper.unmount();
  });

  it('loads existing product data and saved attributes on the dedicated edit route', async () => {
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url === '/api/v1/categories') return json({ data: categories });
      if (url === '/api/v1/brands?per_page=100') return json({ data: brands });
      if (url === '/api/v1/product-conditions?per_page=100') return json({ data: [{ id: 'condition-1', name: 'Novo', active: true }] });
      if (url === '/api/v1/products/product-1') return json(product);
      if (url === '/api/v1/product-attributes?entity_type=produto&all=true') return json({ data: [attribute] });
      if (url === '/api/v1/products/product-1/attributes') return json({ data: [{ product_attribute_id: 'attr-1', value: 'Azul' }] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/erp/produtos/product-1/editar');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Editar produto');
    expect(wrapper.find('#product-condition').exists()).toBe(false);
    expect(wrapper.find('#product-cost-price').exists()).toBe(false);
    expect(wrapper.findAll('fieldset').map((fieldset) => fieldset.get('legend').text())).toEqual(['Identificação', 'Preços', 'Atributos do produto']);
    expect(wrapper.get('#product-name').element.closest('fieldset')?.querySelector('legend')?.textContent).toBe('Identificação');
    expect(wrapper.get('#product-sale-price').element.closest('fieldset')?.querySelector('legend')?.textContent).toBe('Preços');
    expect((wrapper.get('#product-name').element as HTMLInputElement).value).toBe('Telefone A');
    expect((wrapper.get('[data-testid="product-attribute-attr-1"]').element as HTMLInputElement).value).toBe('Azul');
    wrapper.unmount();
  });

  it('manages product images: lists, uploads, sets primary and deletes image', async () => {
    const productWithImages = {
      ...product,
      images: [
        { id: 'img-1', url: 'http://localhost:4566/infocell/images/products/product-1/img1.png', path: 'images/img1.png', is_primary: true, original_name: 'foto1.png' },
        { id: 'img-2', url: 'http://localhost:4566/infocell/images/products/product-1/img2.png', path: 'images/img2.png', is_primary: false, original_name: 'foto2.png' },
      ],
    };

    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url === '/api/v1/categories') return json({ data: categories });
      if (url === '/api/v1/brands?per_page=100') return json({ data: brands });
      if (url === '/api/v1/product-attributes?entity_type=produto&all=true') return json({ data: [] });
      if (url === '/api/v1/products/product-1') return json(productWithImages);
      if (url === '/api/v1/products/product-1/attributes') return json({ data: [] });
      if (url === '/api/v1/products/product-1/images' && init?.method === 'POST') {
        return json({ id: 'img-3', url: 'http://localhost:4566/infocell/images/products/product-1/img3.png', path: 'images/img3.png', is_primary: false, original_name: 'foto3.png' }, 201);
      }
      if (url === '/api/v1/products/product-1/images/img-2/primary' && init?.method === 'PATCH') {
        return json({ id: 'img-2', is_primary: true });
      }
      if (url === '/api/v1/products/product-1/images/img-1' && init?.method === 'DELETE') {
        return json({}, 204);
      }
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);

    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/erp/produtos/product-1/editar');
    await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Imagens do produto');
    expect(wrapper.findAll('img[alt="foto1.png"]')).toHaveLength(1);
    expect(wrapper.findAll('img[alt="foto2.png"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('Principal');

    // Make img-2 primary
    const makePrimaryBtn = wrapper.findAll('button').find((btn) => btn.text().includes('Definir principal'));
    expect(makePrimaryBtn).toBeDefined();
    await makePrimaryBtn!.trigger('click');
    await flushPromises();
    expect(fetch.mock.calls.some(([url]) => String(url).includes('/images/img-2/primary'))).toBe(true);

    // Delete img-1
    const deleteBtn = wrapper.find('button[aria-label="Excluir imagem foto1.png"]');
    expect(deleteBtn.exists()).toBe(true);
    await deleteBtn.trigger('click');
    await flushPromises();
    expect(fetch.mock.calls.some(([url, init]) => String(url).includes('/images/img-1') && init?.method === 'DELETE')).toBe(true);

    // Upload new image
    const fileInput = wrapper.find<HTMLInputElement>('#product-image-upload');
    expect(fileInput.exists()).toBe(true);
    const newFile = new File(['fake content'], 'foto3.png', { type: 'image/png' });
    Object.defineProperty(fileInput.element, 'files', { value: [newFile] });
    await fileInput.trigger('change');
    await flushPromises();

    expect(fetch.mock.calls.some(([url, init]) => String(url).endsWith('/images') && init?.method === 'POST')).toBe(true);

    wrapper.unmount();
  });
});
