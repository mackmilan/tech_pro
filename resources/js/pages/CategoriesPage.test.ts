import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CategoriesPage from './CategoriesPage.vue';

const categories = [
  { id: 'root', name: 'Acessórios', parent_id: null, children: [
    { id: 'child', name: 'Capas', parent_id: 'root', children: [] },
  ] },
];

afterEach(() => vi.unstubAllGlobals());

describe('CategoriesPage', () => {
  it('renders categories in the shared DataGrid with hierarchy and icon-only tooltip actions', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: categories }),
    }));
    const wrapper = mount(CategoriesPage, {
      global: { stubs: { RouterLink: true, Modal: true } },
    });
    await flushPromises();

    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="grouping-header"]').exists()).toBe(true);
    expect(wrapper.findAll('tbody tr').map((row) => row.text())).toEqual([
      expect.stringContaining('Acessórios'),
      expect.stringContaining('Capas'),
    ]);
    expect(wrapper.get('thead th:last-child').text()).toBe('Ações');

    const addChild = wrapper.get('[aria-label="Adicionar subcategoria a Acessórios"]');
    const edit = wrapper.get('[aria-label="Editar Acessórios"]');
    const remove = wrapper.get('[aria-label="Excluir Acessórios"]');
    for (const [button, title] of [[addChild, 'Adicionar subcategoria'], [edit, 'Editar'], [remove, 'Excluir']]) {
      expect(button.element.tagName).toBe('BUTTON');
      expect(button.attributes('title')).toBe(title);
      expect(button.find('svg').exists()).toBe(true);
      expect(button.text().trim()).toBe('');
    }
    expect(addChild.attributes('aria-label')).toBe('Adicionar subcategoria a Acessórios');
    expect(edit.attributes('aria-label')).toBe('Editar Acessórios');
    expect(remove.attributes('aria-label')).toBe('Excluir Acessórios');
    wrapper.unmount();
  });

  it('asks for confirmation before deleting and deletes only after confirmation', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 'leaf', name: 'Acessórios', parent_id: null, children: [] }] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({}) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(CategoriesPage, {
      global: { stubs: { RouterLink: true, Modal: true, ConfirmationDialog: true } },
    });
    await flushPromises();

    await wrapper.get('[aria-label="Excluir Acessórios"]').trigger('click');
    await flushPromises();
    expect(wrapper.getComponent({ name: 'ConfirmationDialog' }).props('modelValue')).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(1);

    await wrapper.getComponent({ name: 'ConfirmationDialog' }).vm.$emit('confirm');
    await flushPromises();
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(fetch.mock.calls[1][0]).toBe('/api/v1/categories/leaf');
    expect(fetch.mock.calls[1][1]).toMatchObject({ method: 'DELETE' });
    wrapper.unmount();
  });
});


