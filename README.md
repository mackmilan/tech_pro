# Infocell Gestão — base do sistema

Base para varejo de celulares e acessórios: Laravel 12, Vue 3 + TypeScript, Tailwind CSS 4, OpenAPI, Keycloak e estoque multi-filial.

## Requisitos

- PHP 8.2+, Composer
- Node.js 20.19+ (ou 22.12+), npm
- SQLite no desenvolvimento (configuração inicial) ou banco suportado pelo Laravel
- Keycloak configurado para autenticação

## Desenvolvimento

```bash
composer install
npm install
php artisan migrate
php artisan serve
npm run dev
```

Abra `http://127.0.0.1:8000`. A interface atual é o shell de navegação dos módulos e fundação inicial; os formulários/fluxos CRUD completos ainda precisam ser implementados.

## Design system

`DESIGN.md` é a referência visual compartilhada pelos agentes: define paleta, tipografia, layout responsivo, componentes e regras para novas telas. Leia-o antes de qualquer alteração de interface e mantenha-o atualizado quando uma mudança de design for deliberada.

## OpenAPI e cliente TypeScript

Os contratos REST são descritos pelo Scramble a partir das rotas/controladores Laravel.

```bash
# mantenha o servidor ativo em outro terminal
php artisan serve
npm run openapi:export   # grava storage/app/openapi.json
npm run api:generate     # usa OPENAPI_SPEC_URL, padrão http://127.0.0.1:8000/docs/api.json
```

Documentação interativa: `/docs/api`; contrato JSON: `/docs/api.json`. O cliente/modelos gerados ficam em `resources/js/api/generated/`; não edite essa pasta manualmente. Defina `OPENAPI_SPEC_URL` para apontar a outra URL do contrato.

## Keycloak

Configure no `.env`:

```dotenv
KEYCLOAK_BASE_URL=http://localhost:8080
KEYCLOAK_REALM=infocell
KEYCLOAK_CLIENT_ID=infocell-web
KEYCLOAK_CLIENT_SECRET=
KEYCLOAK_REDIRECT_URI=http://localhost:8000/auth/callback
```

Crie um cliente OIDC confidential, habilite Authorization Code Flow e cadastre exatamente a URL de callback. Rotas de início/callback/logout em `/auth/redirect`, `/auth/callback`, `/auth/logout`. O acesso ao app Vue e às rotas `/api/v1/*` exige sessão/autenticação; usuários não autenticados são enviados ao Keycloak. O callback associa a conta pelo `sub` (ID estável do Keycloak); o e-mail pode ser opcional e contas sem e-mail recebem nome de usuário/ID como nome local. Para disponibilizar e-mail no perfil, habilite e preencha os atributos de e-mail no usuário do Keycloak e inclua o escopo OIDC `email`. Configure realm/roles, membership, escopo de organizações e segurança da sessão antes de produção.

## Armazenamento de Arquivos / S3 (LocalStack)

Para armazenamento de anexos, documentos e imagens via S3 (em nuvem ou emulados localmente com LocalStack), o projeto utiliza o pacote oficial `league/flysystem-aws-s3-v3`.

### Subindo o LocalStack com Docker Compose

```bash
docker compose up -d localstack
```

O container inicializa o LocalStack na porta `4566` e executa automaticamente o script em `docker/localstack/init-s3.sh` para garantir a criação do bucket `infocell`.

### Configuração no `.env`

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=infocell
AWS_ENDPOINT=http://localhost:4566
AWS_USE_PATH_STYLE_ENDPOINT=true
```

## Módulos e recursos iniciais

- **CRM:** clientes, leads/oportunidades e dados comerciais.
- **ERP:** produtos/SKUs de celulares e acessórios, fornecedores, movimentos e saldos por filial. Cada filial possui endereços/localizações de estoque identificáveis por código e tipo.
- **Caixa:** vendas transacionais, baixa de estoque na localização, pagamentos e ciclo de documento fiscal.
- **Configurações:** filiais, acessos e integrações.

A API já contém CRUDs iniciais de clientes, leads, fornecedores, produtos, filiais e localizações; movimentos e vendas têm endpoints de operação. Isso ainda não entrega o conjunto completo de CRUDs/interface de negócio.

## Fiscal

O endpoint de documento fiscal atualmente cria um registro `pending`; **não transmite nem autoriza NF-e/NFC-e**. Emissão real exige integração com provedor fiscal/SEFAZ, certificado A1/A3, CSC para NFC-e, ambiente/UF, séries, regras tributárias e contingência. A emissão de varejo normalmente é autorizada na SEFAZ estadual; não é uma API única de emissão da Receita Federal. Não usar em produção antes de completar e homologar a integração.

## Antes de produção

Implementar autenticação/autorização nas APIs com OIDC/Sanctum e perfis/escopo por filial, isolamento multi-tenant, regras de transferência/ajustes, integração fiscal real, auditoria e testes de integração; terminar os formulários e fluxos Vue.

## Licença

Este projeto está sob a licença [MIT](LICENSE).

