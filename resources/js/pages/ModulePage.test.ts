import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import ModulePage from './ModulePage.vue';

describe('ERP module navigation cards', () => {
  it('consolidates products and accessories into a single Produtos entry', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/:module', component: ModulePage }],
    });
    await router.push('/erp');
    await router.isReady();
    const wrapper = mount(ModulePage, { global: { plugins: [router] } });
    await flushPromises();

    const cards = wrapper.findAll('article');
    const cardTitles = cards.map((card) => card.get('h2').text());
    expect(cardTitles.filter((title) => title === 'Produção')).toHaveLength(1);
    expect(cardTitles).not.toContain('Produtos');
    expect(cardTitles).not.toContain('Categorias e marcas');
    expect(cardTitles).not.toContain('Condições de produto');
    expect(cardTitles).not.toContain('Atributos de produto');
    expect(cards).toHaveLength(6);
    expect(cards[0].findAll('a').map((link) => link.text())).toEqual([
      'Gerenciar produtos →',
      'Gerenciar categorias →',
      'Gerenciar marcas →',
      'Gerenciar condições →',
      'Gerenciar atributos →',
    ]);
    wrapper.unmount();
  });

  it('renders CRM module cards with direct link to customers', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/:module', component: ModulePage }],
    });
    await router.push('/crm');
    await router.isReady();
    const wrapper = mount(ModulePage, { global: { plugins: [router] } });
    await flushPromises();

    const customerCard = wrapper.findAll('article').find((card) => card.get('h2').text() === 'Clientes');
    expect(customerCard).toBeDefined();
    expect(customerCard!.find('a').text()).toBe('Gerenciar clientes →');
    expect(customerCard!.find('a').attributes('href')).toBe('/crm/clientes');
    wrapper.unmount();
  });
});
