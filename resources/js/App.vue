<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router';
import { ref, watch } from 'vue';
const logout = async () => {
  const response = await fetch('/auth/logout', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
      'Accept': 'application/json',
    },
    credentials: 'same-origin',
  });

  if (!response.ok) {
    throw new Error('Não foi possível encerrar a sessão. Tente novamente.');
  }

  const { redirect_url: redirectUrl } = await response.json() as { redirect_url: string };
  window.location.assign(redirectUrl);
};
const route = useRoute();
const navigationGroups = [
  {
    label: 'Principal',
    items: [
      { label: 'Visão geral', to: '/', icon: 'M3 10.5 12 3l9 7.5M5.25 9v10.5h13.5V9M9 19.5v-6h6v6' },
      {
        label: 'CRM', to: '/crm', icon: 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m12-13a4 4 0 0 1 0 8m6 5v-2a4 4 0 0 0-3-3.87M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', children: [
          { label: 'Ordens de serviço', to: '/crm/ordens-de-servico' },
          { label: 'Serviços', to: '/crm/servicos' },
          { label: 'Checklist de serviços', to: '/crm/checklists' },
          { label: 'Técnicos', to: '/crm/tecnicos' },
          { label: 'Clientes', to: '/crm/clientes' },
          { label: 'Leads e oportunidades', to: '/crm/oportunidades' },
          { label: 'Atendimentos', to: '/crm/atendimentos' },
          { label: 'Campanhas', to: '/crm/campanhas' },
          { label: 'Setores comerciais', to: '/crm/setores-comerciais' },
        ]
      },
      {
        label: 'ERP', to: '/erp', icon: 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z', children: [
          { label: 'Produção', to: '/erp' },
          { label: 'Produtos', to: '/erp/produtos' },
          { label: 'Atributos de produto', to: '/erp/atributos-produto' },
          { label: 'Fornecedores', to: '/erp/fornecedores' },
          { label: 'Compras e recebimentos', to: '/erp/compras' },
          { label: 'Estoque por filial', to: '/erp/estoque' },
          { label: 'Localizações de estoque', to: '/erp/localizacoes' },
          { label: 'Transferências e inventários', to: '/erp/transferencias' },
        ]
      },
      {
        label: 'Caixa', to: '/caixa', icon: 'M3 5h18v14H3zM3 10h18m-5 4h2', children: [
          { label: 'Frente de caixa', to: '/caixa/frente-de-caixa' },
          { label: 'Vendas e pagamentos', to: '/caixa/vendas' },
          { label: 'Devoluções', to: '/caixa/devolucoes' },
          { label: 'Documentos fiscais (NF-e/NFC-e)', to: '/caixa/documentos-fiscais' },
        ]
      },
    ],
  },
  {
    label: 'Administração',
    items: [
      {
        label: 'Configurações', to: '/configuracoes', icon: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0-5v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.06 16.94l-1.42 1.42m12.72 0-1.42-1.42M7.06 7.06 5.64 5.64', children: [
          { label: 'Filiais', to: '/configuracoes/filiais' },
          { label: 'Máquina de estados da OS', to: '/configuracoes/status-ordem-servico' },
          { label: 'Usuários e permissões', to: '/configuracoes/usuarios' },
          { label: 'Integrações', to: '/configuracoes/integracoes' },
          { label: 'Parâmetros fiscais', to: '/configuracoes/parametros-fiscais' },
        ]
      },
    ],
  },
];
const expandedModules = ref<string[]>([]);
const toggleModule = (module: string) => {
  expandedModules.value = expandedModules.value.includes(module)
    ? expandedModules.value.filter((item) => item !== module)
    : [...expandedModules.value, module];
};
watch(() => route.path, (path) => {
  const module = path.split('/')[1];
  if (module && !expandedModules.value.includes(module)) expandedModules.value.push(module);
}, { immediate: true });
</script>
<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <aside class="fixed inset-y-0 left-0 z-20 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
      <div class="flex h-28 shrink-0 items-center border-b border-slate-100 px-6">
        <RouterLink to="/" aria-label="AInfoCell — início" class="block w-full">
          <img :src="'/images/ainfocell-logo.png'" alt="AInfoCell — Conserta na Hora o seu Celular!"
            class="h-auto w-full" />
        </RouterLink>
      </div>
      <nav class="flex-1 space-y-7 overflow-y-auto px-3 py-6">
        <section v-for="group in navigationGroups" :key="group.label">
          <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ group.label }}
          </p>
          <div class="space-y-1">
            <div v-for="item in group.items" :key="item.to">
              <div class="flex items-center">
                <RouterLink :to="item.to"
                  class="group flex min-w-0 flex-1 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                  active-class="!bg-indigo-50 !text-indigo-700">
                  <svg class="h-[18px] w-[18px] shrink-0 text-slate-400 group-hover:text-current" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <path :d="item.icon" />
                  </svg>
                  <span>{{ item.label }}</span>
                </RouterLink>
                <button v-if="item.children" type="button"
                  class="rounded-md p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                  :aria-label="`${expandedModules.includes(item.label.toLowerCase()) ? 'Recolher' : 'Expandir'} ${item.label}`"
                  :aria-expanded="expandedModules.includes(item.label.toLowerCase())"
                  @click="toggleModule(item.label.toLowerCase())">
                  <svg class="h-4 w-4 transition-transform"
                    :class="expandedModules.includes(item.label.toLowerCase()) ? 'rotate-90' : ''" viewBox="0 0 20 20"
                    fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd"
                      d="M7.25 4.5a.75.75 0 0 1 1.06 0l5 5a.75.75 0 0 1 0 1.06l-5 5a.75.75 0 1 1-1.06-1.06L11.72 10 7.25 5.56a.75.75 0 0 1 0-1.06Z"
                      clip-rule="evenodd" />
                  </svg>
                </button>
              </div>
              <div v-if="item.children && expandedModules.includes(item.label.toLowerCase())"
                class="ml-6 mt-1 space-y-1 border-l border-slate-200 pl-3">
                <RouterLink v-for="child in item.children" :key="child.to" :to="child.to"
                  class="block rounded-md px-3 py-2 text-[13px] leading-4 text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                  active-class="!bg-indigo-50 !text-indigo-700 !font-semibold">{{ child.label }}</RouterLink>
              </div>
            </div>
          </div>
        </section>
      </nav>
      <div class="shrink-0 border-t border-slate-100 p-4">
        <div class="rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-5 text-slate-500">
          <p class="font-medium text-slate-600">Multi-filial · API OpenAPI</p>
          <p>Ambiente de desenvolvimento</p>
        </div>
      </div>
    </aside>
    <div class="lg:pl-64">
      <header
        class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white/95 px-5 backdrop-blur sm:px-8">
        <RouterLink to="/" aria-label="AInfoCell — início" class="lg:hidden"><img :src="'/images/ainfocell-logo.png'"
            alt="AInfoCell" class="h-12 w-auto" /></RouterLink>
        <div class="hidden text-sm text-slate-500 lg:block">Sistema de gestão para celulares e acessórios</div>
        <div class="flex items-center gap-3"><span class="hidden text-sm text-slate-500 sm:inline">Sessão protegida via
            Keycloak</span><button type="button" @click="logout"
            class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Sair</button>
        </div>
      </header>
      <main class="w-full p-5 sm:p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
