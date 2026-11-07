<script setup lang="ts">
import { RouterLink } from 'vue-router';
const modules = [
  { name: 'CRM', path: '/crm', desc: 'Clientes, oportunidades e relacionamento' },
  { name: 'ERP', path: '/erp', desc: 'Produtos, compras e estoque por filial' },
  { name: 'Caixa', path: '/caixa', desc: 'Vendas, pagamentos e documentos fiscais' },
  { name: 'Configurações', path: '/configuracoes', desc: 'Filiais, acessos e integrações' },
];
</script>
<template>
  <section>
    <header class="mb-8">
      <p class="text-sm font-medium text-indigo-600">Visão geral</p>
      <h1 class="mt-1 text-3xl font-bold tracking-tight">Painel de gestão</h1>
      <p class="mt-2 text-slate-500">Acompanhe e acesse os módulos da operação.</p>
    </header>
    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <RouterLink v-for="item in modules" :key="item.name" :to="item.path" class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-50/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
        <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-indigo-50 text-lg font-bold text-indigo-700">{{ item.name[0] }}</div>
        <h2 class="mt-5 font-semibold group-hover:text-indigo-700">{{ item.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ item.desc }}</p>
        <div class="mt-5 text-sm font-semibold text-indigo-700">Abrir módulo →</div>
      </RouterLink>
    </div>
    <div class="mt-8 grid gap-5 lg:grid-cols-2">
      <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">Estoque por filial</h2><p class="mt-2 text-sm leading-6 text-slate-500">Cada filial mantém seus próprios depósitos, localizações e saldos. Use transferências rastreáveis para movimentar produtos entre unidades.</p></article>
      <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">API documentada</h2><p class="mt-2 text-sm leading-6 text-slate-500">Contratos OpenAPI servidos pelo Laravel e clientes TypeScript gerados para manter modelos e chamadas alinhados ao backend.</p></article>
    </div>
  </section>
</template>
