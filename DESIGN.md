---
version: alpha
name: Infocell Gestão
description: Sistema administrativo para gestão de varejo de celulares e acessórios; interface operacional clara, consistente e construída com Tailwind CSS 4.
colors:
  primary: "#4F46E5"
  primary-hover: "#4338CA"
  primary-subtle: "#EEF2FF"
  neutral: "#F8FAFC"
  surface: "#FFFFFF"
  text: "#0F172A"
  text-muted: "#64748B"
  border: "#E2E8F0"
  danger: "#B91C1C"
  danger-subtle: "#FEF2F2"
  warning: "#92400E"
  warning-subtle: "#FFFBEB"
  success: "#065F46"
  success-subtle: "#ECFDF5"
typography:
  body:
    fontFamily: ui-sans-serif, system-ui, sans-serif
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.5
  page-title:
    fontFamily: ui-sans-serif, system-ui, sans-serif
    fontSize: 1.875rem
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "-0.025em"
  section-title:
    fontFamily: ui-sans-serif, system-ui, sans-serif
    fontSize: 1.125rem
    fontWeight: 600
    lineHeight: 1.5
  label:
    fontFamily: ui-sans-serif, system-ui, sans-serif
    fontSize: 0.875rem
    fontWeight: 500
    lineHeight: 1.25
rounded:
  control: 8px
  card: 12px
  dialog: 12px
spacing:
  page-mobile: 20px
  page-desktop: 32px
  section-gap: 32px
  card: 20px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "#FFFFFF"
    rounded: "{rounded.control}"
    padding: 8px
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.card}"
    padding: "{spacing.card}"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.control}"
  application-shell:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.text}"
  navigation-active:
    backgroundColor: "{colors.primary-subtle}"
    textColor: "{colors.primary}"
  secondary-text:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-muted}"
  data-grid:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.card}"
  alert-danger:
    backgroundColor: "{colors.danger-subtle}"
    textColor: "{colors.danger}"
  alert-warning:
    backgroundColor: "{colors.warning-subtle}"
    textColor: "{colors.warning}"
  alert-success:
    backgroundColor: "{colors.success-subtle}"
    textColor: "{colors.success}"
  border-separator:
    backgroundColor: "{colors.border}"
    textColor: "{colors.text}"
---

## Overview

Este documento é a referência visual compartilhada por todos os agentes que alteram a interface do Infocell Gestão. Siga-o para telas Vue e para páginas Blade de autenticação. O produto é uma ferramenta administrativa: priorize legibilidade, densidade operacional equilibrada, hierarquia previsível e estados claros; não introduza uma identidade visual concorrente.

A marca exibida no shell é o asset existente `public/images/ainfocell-logo.png`. Preserve-o e não redesenhe, substitua ou aplique filtros sem solicitação explícita. O escopo aqui é a aplicação de gestão, não uma loja virtual pública.

Tokens de cor e medidas representam os valores Tailwind atualmente observados no código. Os nomes dos tokens descrevem seu uso; as classes Tailwind correspondentes são a implementação vigente.

## Colors

- **Primary (#4F46E5):** `indigo-600`, ação primária e identidade interativa. Hover usa `indigo-700` (#4338CA); estados ativos e fundos leves usam `indigo-50` (#EEF2FF), com texto `indigo-700`.
- **Neutral (#F8FAFC):** `slate-50`, fundo geral da aplicação. Superfícies de formulário, tabela, navegação e cartões são brancas (#FFFFFF).
- **Text (#0F172A):** `slate-900` para texto principal; `slate-700` (#334155) para conteúdo e rótulos; `slate-600` (#475569) e `slate-500` (#64748B) para texto secundário. Use tons secundários apenas em informação que não seja essencial.
- **Border (#E2E8F0):** `slate-200` para contornos e divisórias; `slate-100` (#F1F5F9) para separadores discretos. Campos usam `slate-300` (#CBD5E1) quando precisam de contorno mais evidente.
- **Danger (#B91C1C):** `red-600` para ações destrutivas e erro; `red-50` (#FEF2F2) com `red-700` (#B91C1C) para mensagens de erro.
- **Warning (#92400E):** avisos usam `amber-50` (#FFFBEB), borda `amber-200` (#FDE68A) e texto `amber-900` (#78350F).
- **Success (#065F46):** informação positiva usa `emerald-50` (#ECFDF5), borda `emerald-200` (#A7F3D0) e texto `emerald-900` (#064E3B).
- Não use cores por decoração. Reserve vermelho, âmbar e verde para seus estados semânticos; evite novos acentos por módulo sem uma necessidade de produto.

## Typography

Use a pilha sans-serif padrão do Tailwind (`ui-sans-serif, system-ui, sans-serif`); não há fonte customizada configurada no tema atual. Mantenha os textos de produto em português do Brasil.

- Título de página: `text-3xl font-bold tracking-tight` (30px, 700), uma ocorrência principal por tela.
- Subtítulo/descrição: normalmente `text-sm` ou `text-base` em `slate-500`, com espaçamento claro após o título.
- Títulos de seção/cartões: `text-lg font-semibold` ou `font-semibold` conforme a hierarquia.
- Labels e controles: `text-sm font-medium`; cabeçalhos auxiliares de tabela podem usar `text-xs font-semibold uppercase tracking-wide`.
- Use `text-xs` para metadados e não para instruções essenciais. Não reduza tipografia para acomodar conteúdo excessivo; reorganize o layout.

## Layout

O shell usa navegação lateral fixa de 16rem em telas `lg` e conteúdo deslocado por essa largura; a barra superior é sticky e tem 4rem de altura. Em telas pequenas, a navegação lateral desaparece e a marca aparece no cabeçalho. Preserve esse comportamento responsivo e não crie uma segunda navegação concorrente.

O conteúdo principal usa padding de 20px em telas pequenas e 32px a partir de `sm`. Use grids fluidos (`sm:grid-cols-2`, `xl:grid-cols-3/4`) conforme a quantidade de conteúdo. Cabeçalhos de página podem usar `flex flex-wrap`, alinhamento superior e gaps para permitir quebra sem overflow.

Separe cabeçalho de página do conteúdo com cerca de 2rem (`mb-8`). Use gaps de 12–20px em controles/cartões e mantenha densidade adequada a cadastros e tabelas administrativas. Evite larguras rígidas em formulários quando `max-w-*` e grid responsivo resolvem.

## Elevation & Depth

Use borda clara como principal separador. Cartões e grades podem usar `shadow-sm`; diálogos usam `shadow-xl` para indicar sobreposição. Evite sombras decorativas grandes, gradientes e fundos com efeitos que reduzam a clareza dos dados.

## Shapes

- Campos, botões e itens de navegação: cantos arredondados de aproximadamente 8px (`rounded-lg`); ações compactas usam `rounded-md`.
- Cartões, grades e painéis: aproximadamente 12px (`rounded-xl`). O cartão de login pode usar `rounded-2xl`.
- Chips de agrupamento e indicadores em formato de pílula usam `rounded-full` apenas quando o formato comunicar esse papel.
- Use contornos finos (`border`) e evite elementos excessivamente arredondados em tabelas e layouts operacionais.

## Components

- **Ações:** uma ação primária clara por contexto, em `indigo-600` com texto branco e hover `indigo-700`. Ações secundárias usam fundo branco/transparente, borda `slate-300` ou texto neutro. Ação destrutiva é vermelha e deve ser confirmada quando remove dados.
- **Formulários:** labels acima dos controles. Inputs de texto, números e datas seguem o padrão descrito abaixo. Toda escolha entre opções em qualquer interface Vue — filtros, cadastros, seleções simples ou múltiplas e atributos — **deve usar** `resources/js/components/SearchableCombobox.vue`. Não use `<select>` nativo em templates Vue. O combobox publica `string | null` no modo simples e `string[]` no modo múltiplo; forneça opção vazia explícita quando a escolha for opcional e converta o valor vazio ao sentinel de domínio no salvamento. Preserve valores/labels e hierarquia das opções, valor existente em edição, disabled, required, rótulos associados, estados de busca e ausência de resultados. O `for` do label deve corresponder ao `id` do input interno do combobox. Views Blade estão fora deste requisito. O campo padrão ocupa a largura disponível, com `mt-1`, padding horizontal 12px, vertical 8px, `text-sm`, borda `slate-300` e raio de 8px. Mantenha estados de foco visíveis e disabled explícitos.
- **Telas de cadastro:** use cabeçalho com contexto/voltar, título, descrição curta e ação principal; agrupe campos relacionados e mantenha ações de cancelar/salvar no final do formulário.
- **Tabelas e grades:** use o componente compartilhado `resources/js/components/DataGrid.vue` quando adequado. Cabeçalho neutro, linhas com divisórias discretas, padding horizontal de 16px e vertical de 12px; preserve leitura horizontal responsiva e destaque de ordenação, agrupamento, paginação, loading e vazio.
- **Cartões:** fundo branco, borda `slate-200`, `rounded-xl`, padding próximo a 20px e `shadow-sm` quando separar cartões do fundo.
- **Diálogos:** use `resources/js/components/Modal.vue` ou `ConfirmationDialog.vue` antes de criar um padrão paralelo. Overlay escuro translúcido; título, descrição, conteúdo e ações claramente separados. Garanta nomes acessíveis, foco e fechamento compatíveis com o padrão existente.
- **Feedback:** erros usam `role="alert"`, fundo vermelho claro e texto vermelho legível. Avisos e sucesso usam as cores semânticas listadas. Loading e estado vazio devem ser explícitos e não parecer erro.
- **Escolhas:** todos os campos de escolha em interfaces Vue, sejam filtros ou campos de formulário (categorias, marcas, condições, status, entidades, tipos, depósitos e atributos), usam `resources/js/components/SearchableCombobox.vue`; nunca use `<select>` nativo em templates Vue. O combobox simples publica `string | null`, e o múltiplo publica `string[]`. Para uma escolha opcional, inclua `{ value: '', label: '…' }` e converta `''` ao sentinel de domínio correto no salvamento. Preserve rótulos e hierarquia de opções, disabled, required, valor de edição, busca, estado vazio e IDs/`data-testid`; o `for` do label corresponde ao `id` do input interno. Ao migrar, audite selects em `resources/js` antes e depois e teste a seleção renderizada com o componente real. Views fora da UI Vue, como Blade, não estão abrangidas.

## Do's and Don'ts

- Faça: reutilize `App.vue`, componentes compartilhados e tokens/classe Tailwind existentes antes de criar variantes.
- Faça: preserve consistência entre páginas Vue e login Blade; a mesma ação e estado semântico devem ter o mesmo tratamento visual.
- Faça: forneça hover, foco por teclado, disabled, loading, erro e vazio apropriados; mantenha contraste legível e não dependa apenas da cor para comunicar estado.
- Faça: valide a interface em larguras pequenas e grandes; conteúdo não pode ficar encoberto pelo shell, header sticky ou ações da tabela.
- Não faça: introduza outra paleta, tipografia, biblioteca de componentes ou convenção de espaçamento sem atualizar este guia e justificar a necessidade.
- Não faça: usar `resources/js/api/generated/` para guardar UI ou alterar arquivos gerados como parte de um ajuste visual.
- Não faça: adicionar efeitos, gradientes, sombras fortes ou animações sem finalidade funcional.

## Fontes de verdade

- Tema CSS: `resources/css/app.css` (importa Tailwind CSS 4; não há configuração customizada de fonte ou paleta).
- Shell e navegação responsiva: `resources/js/App.vue`.
- Padrões compartilhados: `resources/js/components/DataGrid.vue`, `Modal.vue` e `ConfirmationDialog.vue`.
- Exemplos de páginas: `resources/js/pages/Dashboard.vue`, páginas de cadastros e `resources/views/auth/login.blade.php`.
- Implementação atual e dependências: `package.json`, `vite.config.js` e classes Tailwind nos arquivos acima.
- Este documento registra o padrão visual; se o código divergir, reconcilie o componente com o padrão, não copie a divergência como um novo padrão sem revisar esta especificação.
