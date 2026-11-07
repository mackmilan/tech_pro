import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { RouterLink } from 'vue-router';
import App from '../App.vue';
import router from '../router';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('filiais navigation', () => {
  it('uses the full sidebar width for the logo', async () => {
    await router.push('/');
    await router.isReady();
    const wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } });
    const logo = wrapper.get('aside img[alt^="AInfoCell"]');

    const logoContainer = logo.element.parentElement?.parentElement;
    expect(logo.classes()).toContain('w-full');
    expect(logo.classes()).toContain('h-auto');
    expect(logo.classes()).not.toContain('max-h-20');
    expect(logo.classes()).not.toContain('object-fill');
    expect(logoContainer?.classList.contains('h-28')).toBe(true);
    expect(logoContainer?.classList.contains('px-6')).toBe(true);
    wrapper.unmount();
  });

  it('shows a collapsible submenu with every item under each module', async () => {
    await router.push('/');
    await router.isReady();
    const wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } });

    for (const module of ['CRM', 'ERP', 'Caixa', 'Configurações']) {
      await wrapper.get(`button[aria-label="Expandir ${module}"]`).trigger('click');
    }

    const expectedLinks = [
      ['/crm/ordens-de-servico', 'Ordens de serviço'],
      ['/crm/servicos', 'Serviços'],
      ['/crm/checklists', 'Checklist de serviços'],
      ['/crm/clientes', 'Clientes'],
      ['/crm/oportunidades', 'Leads e oportunidades'],
      ['/crm/atendimentos', 'Atendimentos'],
      ['/crm/campanhas', 'Campanhas'],
      ['/crm/setores-comerciais', 'Setores comerciais'],
      ['/erp', 'Produção'],
      ['/erp/produtos', 'Produtos'],
      ['/erp/atributos-produto', 'Atributos de produto'],
      ['/erp/fornecedores', 'Fornecedores'],
      ['/erp/compras', 'Compras e recebimentos'],
      ['/erp/estoque', 'Estoque por filial'],
      ['/erp/localizacoes', 'Localizações de estoque'],
      ['/erp/transferencias', 'Transferências e inventários'],
      ['/caixa/frente-de-caixa', 'Frente de caixa'],
      ['/caixa/vendas', 'Vendas e pagamentos'],
      ['/caixa/devolucoes', 'Devoluções'],
      ['/caixa/documentos-fiscais', 'Documentos fiscais (NF-e/NFC-e)'],
      ['/configuracoes/filiais', 'Filiais'],
      ['/configuracoes/usuarios', 'Usuários e permissões'],
      ['/configuracoes/integracoes', 'Integrações'],
      ['/configuracoes/parametros-fiscais', 'Parâmetros fiscais'],
    ];

    for (const [path, label] of expectedLinks) {
      const link = wrapper.findAll('a').find((candidate) => candidate.text() === label);
      expect(link?.attributes('href')).toBe(path);
    }
    wrapper.unmount();
  });

  it('opens the branch CRUD from the settings module card', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
    vi.stubGlobal('fetch', fetch);
    await router.push('/configuracoes');
    await router.isReady();
    const wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();

    const link = wrapper.findAllComponents(RouterLink).find((candidate) => candidate.props('to') === '/configuracoes/filiais');
    expect(link).toBeDefined();
    await link!.trigger('click');
    await flushPromises();

    expect(router.currentRoute.value.path).toBe('/configuracoes/filiais');
    expect(wrapper.get('h1').text()).toBe('Filiais');
    expect(fetch).toHaveBeenCalledWith('/api/v1/branches', { headers: { Accept: 'application/json' } });
    wrapper.unmount();
  });
});
