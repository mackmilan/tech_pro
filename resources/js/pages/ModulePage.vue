<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';

const route = useRoute();
const title = computed(() => String(route.params.module ?? '').toUpperCase());
const descriptions: Record<string, string[]> = {
  crm: ['Ordens de serviço', 'Serviços', 'Checklist de serviços', 'Técnicos', 'Clientes', 'Leads e oportunidades', 'Atendimentos', 'Campanhas', 'Setores comerciais'],
  erp: ['Produção', 'Fornecedores', 'Compras e recebimentos', 'Estoque por filial', 'Localizações de estoque', 'Transferências e inventários'],
  caixa: ['Frente de caixa', 'Vendas e pagamentos', 'Devoluções', 'Documentos fiscais (NF-e/NFC-e)'],
  configuracoes: ['Filiais', 'Máquina de estados da OS', 'Usuários e permissões', 'Integrações', 'Parâmetros fiscais'],
};
const items = computed(() => descriptions[String(route.params.module)] ?? []);
</script>

<template>
  <section>
    <header class="mb-8">
      <p class="text-sm font-medium text-indigo-600">Módulo</p>
      <h1 class="mt-1 text-3xl font-bold tracking-tight">{{ title }}</h1>
      <p class="mt-2 text-slate-500">Gerencie as rotinas de {{ title.toLowerCase() }} da operação.</p>
    </header>

    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <article v-for="(item, i) in items" :key="item" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div class="rounded-lg bg-indigo-50 p-2 text-indigo-700">{{ String(i + 1).padStart(2, '0') }}</div>
          <span class="text-xs text-slate-400">Cadastro</span>
        </div>
        <h2 class="mt-5 font-semibold">{{ item }}</h2>
        <p class="mt-1 text-sm text-slate-500">Consulte e mantenha os registros desta área.</p>
        <div v-if="item === 'Produção'" class="mt-5 flex flex-wrap gap-x-4 gap-y-2 text-sm font-semibold text-indigo-700">
          <RouterLink to="/erp/produtos" class="transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar produtos →</RouterLink>
          <RouterLink to="/erp/categorias" class="transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar categorias →</RouterLink>
          <RouterLink to="/erp/marcas" class="transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar marcas →</RouterLink>
          <RouterLink to="/erp/condicoes-produto" class="transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar condições →</RouterLink>
          <RouterLink to="/erp/atributos-produto" class="transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar atributos →</RouterLink>
        </div>
        <RouterLink v-else-if="item === 'Ordens de serviço'" to="/crm/ordens-de-servico" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar ordens de serviço →</RouterLink>
        <RouterLink v-else-if="item === 'Serviços'" to="/crm/servicos" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar catálogo de serviços →</RouterLink>
        <RouterLink v-else-if="item === 'Checklist de serviços'" to="/crm/checklists" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar checklists →</RouterLink>
        <RouterLink v-else-if="item === 'Técnicos'" to="/crm/tecnicos" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar técnicos →</RouterLink>
        <RouterLink v-else-if="item === 'Clientes'" to="/crm/clientes" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar clientes →</RouterLink>
        <RouterLink v-else-if="item === 'Fornecedores'" to="/erp/fornecedores" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar fornecedores →</RouterLink>
        <RouterLink v-else-if="item === 'Compras e recebimentos'" to="/erp/compras" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar compras →</RouterLink>
        <RouterLink v-else-if="item === 'Filiais'" to="/configuracoes/filiais" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar filiais →</RouterLink>
        <RouterLink v-else-if="item === 'Máquina de estados da OS'" to="/configuracoes/status-ordem-servico" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Configurar máquina de estados →</RouterLink>
        <RouterLink v-else-if="item === 'Estoque por filial'" to="/erp/estoque" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Consultar estoque →</RouterLink>
        <RouterLink v-else-if="item === 'Localizações de estoque'" to="/erp/localizacoes" class="mt-5 block text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Gerenciar localizações →</RouterLink>
        <button v-else type="button" class="mt-5 text-sm font-semibold text-indigo-700 transition-colors hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Acessar →</button>
      </article>
    </div>

    <div v-if="route.params.module === 'caixa'" class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 shadow-sm"><strong>Integração fiscal:</strong> emissão depende de certificado digital, credenciamento estadual e configuração fiscal da empresa. O sistema deve enviar documentos à SEFAZ; a Receita Federal mantém serviços nacionais como o Portal Nacional da NF-e.</div>
    <div v-if="route.params.module === 'erp'" class="mt-8 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-700 shadow-sm"><strong>Estoque multi-filial:</strong> saldos e endereços (depósito, corredor, prateleira e posição) são vinculados à filial, com transferência entre filiais registrada.</div>
  </section>
</template>
