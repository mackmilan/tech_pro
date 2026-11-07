import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DataGrid from './DataGrid.vue';

const columns = [
  { key: 'name', label: 'Nome', sortable: true, groupable: true },
  { key: 'email', label: 'E-mail', sortable: true, groupable: true },
];
const rows = [
  { id: '1', name: 'Bia', email: 'bia@example.test' },
  { id: '2', name: 'Ana', email: 'ana@example.test' },
  { id: '3', name: 'Ana', email: 'ana2@example.test' },
];

describe('DataGrid', () => {
  it('sorts data, paginates, and keeps actions in a fixed right column', async () => {
    const wrapper = mount(DataGrid, {
      props: { columns, rows, pageSize: 2 },
      slots: { actions: '<button>Editar</button>' },
    });

    expect(wrapper.findAll('tbody tr').map((row) => row.text())).toHaveLength(2);
    expect(wrapper.get('thead th:last-child').text()).toBe('Ações');
    expect(wrapper.get('thead th:last-child').classes()).toContain('sticky');

    await wrapper.get('button[aria-label="Ordenar por Nome"]').trigger('click');
    expect(wrapper.findAll('tbody tr')[0].text()).toContain('Ana');
    expect(wrapper.findAll('tbody tr')[1].text()).toContain('Ana');
    expect(wrapper.get('[aria-label="Página 2"]').exists()).toBe(true);
    await wrapper.get('[aria-label="Próxima página"]').trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    expect(wrapper.text()).toContain('Bia');
    wrapper.unmount();
  });

  it('hides the actions column when a list renders its data as a column', () => {
    const wrapper = mount(DataGrid, { props: { columns: [...columns, { key: 'attributes', label: 'Atributos' }], rows, showActions: false } });

    expect(wrapper.get('thead th:last-child').text()).toBe('Atributos');
    expect(wrapper.find('thead th').text()).not.toContain('Ações');
    expect(wrapper.findAll('tbody tr')[0].findAll('td')).toHaveLength(3);
    wrapper.unmount();
  });

  it('shows loading and empty states supplied by the consuming list', () => {
    const loading = mount(DataGrid, {
      props: { columns, rows: [], loading: true, emptyMessage: 'Nenhuma categoria cadastrada.' },
    });
    expect(loading.text()).toContain('Carregando…');
    expect(loading.text()).not.toContain('Nenhuma categoria cadastrada.');
    loading.unmount();

    const empty = mount(DataGrid, {
      props: { columns, rows: [], emptyMessage: 'Nenhuma categoria cadastrada.' },
    });
    expect(empty.text()).toContain('Nenhuma categoria cadastrada.');
    empty.unmount();
  });

  it('groups rows by dragging a groupable column to the grouping header', async () => {
    const wrapper = mount(DataGrid, { props: { columns, rows } });
    const groupingHeader = wrapper.get('[data-testid="grouping-header"]');
    await wrapper.get('[data-column-key="name"]').trigger('dragstart', { dataTransfer: { setData() {} } });
    await groupingHeader.trigger('drop', { dataTransfer: { getData: () => 'name' } });

    expect(wrapper.text()).toContain('Agrupado por: Nome');
    expect(wrapper.text()).toContain('Ana (2)');
    expect(wrapper.text()).toContain('Bia (1)');
    wrapper.unmount();
  });
});
