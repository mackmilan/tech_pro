import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockPage from './StockPage.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('StockPage', () => {
  it('lists stock items with product and branch names, filtering by branch', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true, json: async () => ({
        data: [
          { id: 'item-1', stock_id: 'stock-1', product_id: 'product-1', quantity: 7, product: { sku: 'CEL-1', name: 'Celular A' }, stock: { branch: { id: 'branch-1', name: 'Filial Centro', code: 'CENTRO' } } },
          { id: 'item-2', stock_id: 'stock-2', product_id: 'product-2', quantity: 2, product: { sku: 'FONE-2', name: 'Fone B' }, stock: { branch: { id: 'branch-2', name: 'Filial Norte', code: 'NORTE' } } },
        ]
      })
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();

    expect(fetch).toHaveBeenCalledWith('/api/v1/stock-items?per_page=100', { headers: { Accept: 'application/json' } });
    expect(wrapper.get('h1').text()).toBe('Estoque');
    expect(wrapper.find('[data-testid="data-grid"]').exists()).toBe(true);
    expect(wrapper.get('thead th:last-child').text()).toBe('Atributos');
    expect(wrapper.text()).toContain('Celular A');
    expect(wrapper.text()).toContain('Filial Centro');
    expect(wrapper.text()).toContain('Fone B');
    await wrapper.get('#stock-search').setValue('CENTRO');
    expect(wrapper.text()).toContain('Celular A');
    expect(wrapper.text()).not.toContain('Fone B');
    wrapper.unmount();
  });

  it('links to a dedicated page instead of rendering the entry form inline', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) }));
    const wrapper = mount(StockPage, { global: { stubs: { RouterLink: { template: '<a :href="to" :aria-label="$attrs[\'aria-label\']"><slot /></a>', props: ['to'] } } } });
    await flushPromises();

    expect(wrapper.find('form[aria-label="Lançamento de entrada"]').exists()).toBe(false);
    const link = wrapper.find('a[aria-label="Lançar entrada de estoque"]');
    expect(link.attributes('href')).toBe('/erp/estoque/novo-lancamento');
    wrapper.unmount();
  });

  it('separates stock items by stock-controlling attributes into distinct rows and shows only stock-controlling attributes', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true, json: async () => ({
        data: [
          {
            id: 'item-1', quantity: 3,
            product: { name: 'Celular A', sku: 'CEL-1' },
            stock: { branch: { name: 'Filial Centro', code: 'CENTRO' } },
            attributes: [
              { name: 'Armazenamento', value: '128 GB', differentiate_stock: true },
              { name: 'Cor', value: ['Preto', 'Azul'], differentiate_stock: true },
              { name: 'Voltagem', value: ['110V', '110V'], differentiate_stock: true },
              { name: 'Recursos', value: ['5G', 'NFC'], differentiate_stock: false },
              { name: 'Novo', value: true, differentiate_stock: false },
            ],
          },
          {
            id: 'item-2', quantity: 1,
            product: { name: 'Fone B', sku: 'FONE-2' },
            stock: { branch: { name: 'Filial Norte', code: 'NORTE' } },
            attributes: [
              { name: 'Lote', value: 'LOTE-123', differentiate_stock: false },
            ],
          },
        ]
      })
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();

    expect(wrapper.get('thead th:nth-child(5)').text()).toBe('Atributos');
    expect(wrapper.get('thead th:last-child').text()).toBe('Atributos');
    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(3);
    const firstAttributeCell = rows[0].findAll('td')[4];
    expect(firstAttributeCell.text()).toBe('Armazenamento: 128 GB\nCor: Preto\nVoltagem: 110V');
    expect(firstAttributeCell.find('span').classes()).toContain('whitespace-pre-line');
    const secondAttributeCell = rows[1].findAll('td')[4];
    expect(secondAttributeCell.text()).toBe('Armazenamento: 128 GB\nCor: Azul\nVoltagem: 110V');
    const thirdAttributeCell = rows[2].findAll('td')[4];
    expect(thirdAttributeCell.text()).toBe('—');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    expect(firstAttributeCell.find('button').exists()).toBe(false);
    expect(fetch).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it('groups rows by unit-level differentiating values and opens scoped modal for that variation', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true, json: async () => ({
        data: [
          {
            id: 'item-1',
            quantity: 3,
            product: { id: 'prod-1', name: 'Galaxy S26', sku: 'GALAXY-26' },
            stock: { id: 'stock-1', branch: { id: 'branch-1', name: 'Matriz', code: 'MATRIZ' } },
            attributes: [
              { product_attribute_id: 'attr-cor', name: 'Cor', value: ['Vermelho', 'Roxo'], differentiate_stock: true },
              { product_attribute_id: 'attr-imei', name: 'IMEI', value: null, per_unit: true, differentiate_stock: false },
            ],
            units: [
              { id: 'unit-1', condition_id: 'c1', condition: { name: 'Novo' }, unit_cost: 1500, attributes: { 'attr-cor': 'Vermelho', 'attr-imei': 'IMEI-1' } },
              { id: 'unit-2', condition_id: 'c1', condition: { name: 'Novo' }, unit_cost: 1500, attributes: { 'attr-cor': 'Vermelho', 'attr-imei': 'IMEI-2' } },
              { id: 'unit-3', condition_id: 'c1', condition: { name: 'Novo' }, unit_cost: 1600, attributes: { 'attr-cor': 'Roxo', 'attr-imei': 'IMEI-3' } },
            ],
          },
        ]
      })
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, { attachTo: document.body, global: { stubs: { RouterLink: true } } });
    await flushPromises();

    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);

    // Row 1: Vermelho with 2 units
    expect(rows[0].findAll('td')[0].text()).toContain('Galaxy S26');
    expect(rows[0].findAll('td')[3].text()).toContain('2');
    expect(rows[0].findAll('td')[4].text()).toBe('Cor: Vermelho');

    // Row 2: Roxo with 1 unit
    expect(rows[1].findAll('td')[0].text()).toContain('Galaxy S26');
    expect(rows[1].findAll('td')[3].text()).toContain('1');
    expect(rows[1].findAll('td')[4].text()).toBe('Cor: Roxo');

    // Clicking "Ver itens" on Row 1 opens modal with 2 Vermelho units only
    await rows[0].findAll('td')[3].find('button').trigger('click');
    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog?.textContent).toContain('Total de unidades: 2');
    expect(dialog?.textContent).toContain('IMEI-1');
    expect(dialog?.textContent).toContain('IMEI-2');
    expect(dialog?.textContent).not.toContain('IMEI-3');

    (dialog?.querySelector('footer button') as HTMLButtonElement).click();
    await flushPromises();

    wrapper.unmount();
  });

  it('does not list individual unit items in the attributes column', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true, json: async () => ({
        data: [
          {
            id: 'item-1', quantity: 2, product: { name: 'Celular A', sku: 'CEL-1' }, stock: { branch: { name: 'Filial Centro', code: 'CENTRO' } }, attributes: [
              { name: 'Cor', value: 'Vermelho', differentiate_stock: true },
            ], units: [
              { id: 'unit-1', condition_id: 'condition-1', condition: { id: 'condition-1', name: 'Novo' }, unit_cost: '100.00', attributes: { imei: 'IMEI-1' } },
              { id: 'unit-2', condition_id: 'condition-2', condition: { id: 'condition-2', name: 'Seminovo' }, unit_cost: '80.00', attributes: { imei: 'IMEI-2' } },
            ]
          },
        ]
      })
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();
    expect(wrapper.text()).toContain('Cor: Vermelho');
    expect(wrapper.text()).not.toContain('Unidade 1');
    expect(wrapper.text()).not.toContain('Unidade 2');
    expect(wrapper.text()).not.toContain('IMEI-1');
    expect(wrapper.text()).not.toContain('IMEI-2');
    wrapper.unmount();
  });

  it('opens a modal with individual units and their attributes when clicking the action in the quantity column', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true, json: async () => ({
        data: [
          {
            id: 'item-1',
            quantity: 2,
            product: { id: 'prod-1', name: 'Galaxy S26', sku: '2134234fbd' },
            stock: { id: 'stock-1', branch: { id: 'branch-1', name: 'Matriz', code: 'MATRIZ' } },
            attributes: [
              { product_attribute_id: 'attr-color', name: 'Cor', value: 'Vermelho', differentiate_stock: true },
              { product_attribute_id: 'attr-imei', name: 'IMEI', value: null, per_unit: true, differentiate_stock: false },
            ],
            units: [
              {
                id: 'unit-1',
                condition_id: 'cond-1',
                condition: { id: 'cond-1', name: 'Novo' },
                unit_cost: '1500.00',
                attributes: { 'attr-imei': '31432482', 'attr-color': 'Vermelho' },
              },
              {
                id: 'unit-2',
                condition_id: 'cond-2',
                condition: { id: 'cond-2', name: 'Seminovo' },
                unit_cost: '1200.00',
                attributes: { 'attr-imei': '99887766', 'attr-color': 'Vermelho' },
              },
            ],
          },
          {
            id: 'item-2',
            quantity: 5,
            product: { id: 'prod-2', name: 'Cabo USB-C', sku: 'CABO-1' },
            stock: { id: 'stock-1', branch: { id: 'branch-1', name: 'Matriz', code: 'MATRIZ' } },
            attributes: [],
            units: [],
          },
        ],
      }),
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, {
      attachTo: document.body,
      global: { stubs: { RouterLink: true } },
    });
    await flushPromises();

    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);

    const firstQuantityCell = rows[0].findAll('td')[3];
    expect(firstQuantityCell.text()).toContain('2');
    const viewButton = firstQuantityCell.find('button');
    expect(viewButton.exists()).toBe(true);
    expect(viewButton.text()).toBe('Ver itens');
    expect(viewButton.attributes('aria-label')).toBe('Ver itens individuais de Galaxy S26');

    const secondQuantityCell = rows[1].findAll('td')[3];
    expect(secondQuantityCell.text()).toBe('5');
    expect(secondQuantityCell.find('button').exists()).toBe(false);

    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    await viewButton.trigger('click');

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain('Itens individuais — Galaxy S26');
    expect(dialog?.textContent).toContain('Filial: Matriz (MATRIZ) · Total de unidades: 2');
    expect(dialog?.textContent).toContain('Unidade 1');
    expect(dialog?.textContent).toContain('Condição');
    expect(dialog?.textContent).toContain('Novo');
    expect(dialog?.textContent).toContain('Origem');
    expect(dialog?.textContent).toContain('Localização no estoque');
    expect(dialog?.textContent).toContain('R$ 1500.00');
    expect(dialog?.textContent).toContain('IMEI');
    expect(dialog?.textContent).toContain('31432482');
    expect(dialog?.textContent).toContain('Unidade 2');
    expect(dialog?.textContent).toContain('Seminovo');
    expect(dialog?.textContent).toContain('R$ 1200.00');
    expect(dialog?.textContent).toContain('99887766');

    const closeButton = dialog?.querySelector('footer button') as HTMLButtonElement;
    expect(closeButton.textContent?.trim()).toBe('Fechar');
    closeButton.click();
    await flushPromises();

    expect(document.body.querySelector('[role="dialog"]')).toBeNull();

    wrapper.unmount();
  });

  it('displays origin and stock location per unit in the items detail modal', async () => {
    const fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        data: [
          {
            id: 'item-1',
            quantity: 2,
            product: { id: 'prod-1', name: 'iPhone 15 Pro', sku: 'IPHONE15P' },
            stock: { id: 'stock-1', branch: { id: 'branch-1', name: 'Filial Centro', code: 'CENTRO' } },
            attributes: [],
            units: [
              {
                id: 'unit-1',
                condition_id: 'cond-1',
                condition: { id: 'cond-1', name: 'Novo' },
                unit_cost: '4500.00',
                source: 'fornecedor',
                stock_location_id: 'loc-1',
                stock_location: { id: 'loc-1', name: 'Depósito Principal', code: 'DEP-1' },
                attributes: { imei: '354892019283741' },
              },
              {
                id: 'unit-2',
                condition_id: 'cond-2',
                condition: { id: 'cond-2', name: 'Excelente' },
                unit_cost: '3800.00',
                source: 'aparelho',
                stock_location_id: 'loc-2',
                stock_location: { id: 'loc-2', name: 'Vitrine', code: 'VIT-A' },
                attributes: { imei: '354892019283742' },
              },
            ],
          },
        ],
      }),
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(StockPage, {
      attachTo: document.body,
      global: { stubs: { RouterLink: true } },
    });
    await flushPromises();

    const viewButton = wrapper.find('tbody tr td button');
    expect(viewButton.exists()).toBe(true);
    await viewButton.trigger('click');

    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.textContent).toContain('Itens individuais — iPhone 15 Pro');
    expect(dialog?.textContent).toContain('Filial: Filial Centro (CENTRO) · Total de unidades: 2');

    // Unit 1
    expect(dialog?.textContent).toContain('Unidade 1');
    expect(dialog?.textContent).toContain('Fornecedor');
    expect(dialog?.textContent).toContain('Depósito Principal (DEP-1)');
    expect(dialog?.textContent).toContain('R$ 4500.00');

    // Unit 2
    expect(dialog?.textContent).toContain('Unidade 2');
    expect(dialog?.textContent).toContain('Aparelho (entrada para compra)');
    expect(dialog?.textContent).toContain('Vitrine (VIT-A)');
    expect(dialog?.textContent).toContain('R$ 3800.00');

    (dialog?.querySelector('footer button') as HTMLButtonElement).click();
    await flushPromises();

    wrapper.unmount();
  });

  it('shows an error if the inventory request fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({ message: 'Erro ao carregar itens.' }) }));
    const wrapper = mount(StockPage, { global: { stubs: { RouterLink: true } } });
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toBe('Erro ao carregar itens.');
    wrapper.unmount();
  });
});
