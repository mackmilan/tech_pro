import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ProductsPage from './ProductsPage.vue';

const product = { id: 'product-1', sku: 'PHONE-1', name: 'Telefone A', category: 'Celulares', category_id: 'cat-1', brand: 'Acme', brand_id: 'brand-1', sale_price: '150.00', active: true, category_relation: { id: 'cat-1', name: 'Celulares' }, brand_relation: { id: 'brand-1', name: 'Acme' } };
const json = (data: unknown, status = 200) => ({ ok: true, status, json: async () => data }) as Response;
const ConfirmationStub = { props: ['modelValue'], emits: ['confirm'], template: '<div v-if="modelValue"><button data-testid="confirm-delete" @click="$emit(\'confirm\')">Confirmar exclusão</button></div>' };
afterEach(() => vi.unstubAllGlobals());

describe('ProductsPage', () => {
  it('lists and searches products, links to dedicated create/edit pages, and deletes with confirmation', async () => {
    const fetch = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
      const url = String(input);
      if (url.startsWith('/api/v1/products?')) return json({ data: [product], current_page: 1, last_page: 1 });
      if (url === '/api/v1/products/product-1' && init?.method === 'DELETE') return json({}, 204);
      if (url === '/api/v1/categories') return json({ data: [{ id: 'cat-1', name: 'Celulares', parent_id: null, children: [] }] });
      if (url === '/api/v1/brands?per_page=100') return json({ data: [{ id: 'brand-1', name: 'Acme', active: true }] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/erp/produtos', component: ProductsPage }, { path: '/erp/produtos/novo', component: { template: '<h1>Novo produto</h1>' } }, { path: '/erp/produtos/:id/editar', component: { template: '<h1>Editar produto</h1>' } }] });
    await router.push('/erp/produtos'); await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router], stubs: { ConfirmationDialog: ConfirmationStub } } });
    await flushPromises();
    expect(wrapper.findAll('input[role="combobox"]').map((input) => input.attributes('id'))).toEqual(['products-category-filter', 'products-brand-filter', 'products-status-filter']);
    await wrapper.get('#products-category-filter').trigger('focus');
    await wrapper.get('#products-brand-filter').trigger('focus');
    const categoryOptions = wrapper.findAll('#products-category-filter-listbox [role="option"]');
    expect(categoryOptions.map((option) => option.text().replace('✓', '')).map((text) => text.trim())).toEqual(['Todas as categorias', 'Celulares']);
    await categoryOptions[1].trigger('click');
    await flushPromises();
    await wrapper.find('#products-brand-filter-listbox [role="option"]').trigger('click');
    await flushPromises();
    expect(fetch).toHaveBeenLastCalledWith(expect.stringContaining('category_id=cat-1'), expect.anything());
    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Telefone A'); expect(wrapper.text()).toContain('Acme');
    await wrapper.get('input[type="search"]').setValue('PHONE'); await flushPromises();
    expect(fetch.mock.calls.some(([url]) => String(url).includes('search=PHONE'))).toBe(true);
    expect(wrapper.get('a[href="/erp/produtos/novo"]').text()).toContain('Novo produto');
    expect(wrapper.get('a[href="/erp/produtos/product-1/editar"]').exists()).toBe(true);
    await wrapper.get('a[href="/erp/produtos/novo"]').trigger('click'); await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe('/erp/produtos/novo');
    await router.push('/erp/produtos'); await flushPromises();
    await wrapper.get('[aria-label="Excluir Telefone A"]').trigger('click');
    expect(wrapper.text()).toContain('Confirmar exclusão');
    await wrapper.get('[data-testid="confirm-delete"]').trigger('click'); await flushPromises();
    expect(fetch.mock.calls.some(([url, init]) => String(url) === '/api/v1/products/product-1' && init?.method === 'DELETE')).toBe(true);
    wrapper.unmount();
  });

  it('renders product image thumbnail when product has images', async () => {
    const productWithImg = {
      ...product,
      images: [
        { id: 'img-1', url: 'http://localhost:4566/infocell/images/products/p1.webp', is_primary: true },
      ],
    };
    const fetch = vi.fn(async (input: string | URL | Request) => {
      const url = String(input);
      if (url.startsWith('/api/v1/products?')) return json({ data: [productWithImg], current_page: 1, last_page: 1 });
      if (url === '/api/v1/categories') return json({ data: [{ id: 'cat-1', name: 'Celulares', parent_id: null, children: [] }] });
      if (url === '/api/v1/brands?per_page=100') return json({ data: [{ id: 'brand-1', name: 'Acme', active: true }] });
      throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/erp/produtos', component: ProductsPage }] });
    await router.push('/erp/produtos'); await router.isReady();
    const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router], stubs: { ConfirmationDialog: ConfirmationStub } } });
    await flushPromises();

    const img = wrapper.find('img[alt="Telefone A"]');
    expect(img.exists()).toBe(true);
    expect(img.attributes('src')).toBe('http://localhost:4566/infocell/images/products/p1.webp');
    wrapper.unmount();
  });
});
