# Keycloak realm - Infocell

Import definition for Keycloak with Organizations enabled. The included organization is an **example seed for local/dev only**, without email domains or seeded users. Remove or replace it before production; production branch organizations and memberships should be created through an approved provisioning workflow.

## Import

On a new Keycloak instance, import the realm JSON at startup, for example:

```bash
/opt/keycloak/bin/kc.sh start-dev --import-realm
```

Place `realm-infocell.json` at `/opt/keycloak/data/import/realm-infocell.json` in the container. Startup import generally only imports realms that do not yet exist; it does not reconcile changes to an already existing realm. For an existing realm, apply the settings/resources through Keycloak Admin REST API or re-import into a disposable instance after backup.

File: `keycloak/realm-infocell.json`

## Login Keycloak e PKCE

A aplicação inicia o fluxo em `/auth/redirect` com PKCE `S256` (`code_challenge` e `code_challenge_method`). O callback envia o `code_verifier` guardado na sessão Laravel ao trocar o authorization code. Se o provedor voltar com `error` ou a sessão/state já expirou, o callback encerra com redirecionamento para novo login em vez de tratar como autenticação concluída. Depois de atualizar, inicie um fluxo novo; solicitações de autorização antigas não levam os parâmetros PKCE.


- `organizationsEnabled: true` enables Organizations for realm `infocell`.
- `infocell-web` has the built-in `organization` client scope attached as **optional**. Request the `organization` scope at OIDC login; users belonging to multiple organizations may be asked to select one.
- Request `organization:*` only if the application intentionally needs all memberships without organization selection. Validate the exact Keycloak version and its organization scope behavior.
- The organization membership mapper can produce the `organization` claim. Users need explicit membership in an organization before that claim is available.
- `infocell-matriz` is a sample alias with a `branchCode` attribute, not a real branch record or email-domain mapping. Keycloak aliases are identifiers and should be treated as immutable.
- For multiple branch memberships, use stable organization aliases and custom `branchCode` attributes. Bind each authenticated membership to the corresponding application branch and still enforce branch authorization in the backend; token claims alone should not replace authorization checks.

A integração de filiais usa a Admin REST API do Keycloak para criar, atualizar e excluir organizações. O JSON do realm habilita a service account de `infocell-api`, atribui a role `manage-organizations` de `realm-management` e inclui essa role nos `clientScopeMappings` do client para que ela possa constar no token. Configure `KEYCLOAK_ADMIN_CLIENT_SECRET` com o segredo do client confidencial `infocell-api`. Essa role está disponível a partir do Keycloak 26.7; versões anteriores exigem uma configuração compatível com a versão instalada. A criação da filial falha sem persistir o registro local se o Keycloak não criar a organização. Alterações de nome, código/status e exclusão também sincronizam com o Keycloak; uma falha de sincronização retorna erro em vez de confirmar a operação.

O import de inicialização aplica-se a realms novos e não atualiza um realm já existente. Para realms existentes, aplique as role mappings da service account e do client via Admin Console ou Admin REST API, ou reimporte a configuração em uma instância descartável após backup. Verifique que `manage-organizations` aparece no access token de `infocell-api`; não conceda `realm-admin` como alternativa ampla.

O CRUD usa alias estável `branch-{UUID}` e grava `branchCode`/`organizationType=branch` nos atributos da organização. As organizações e memberships existentes precisam ser provisionadas separadamente; criar a organização não associa usuários automaticamente.

`infocell-web` is a confidential OIDC client for the Laravel server-side Authorization Code flow. Replace the local redirect URI and origin for each deployment and provision the client secret in Keycloak/secret storage; do not commit production secrets into this import file. `infocell-api` is a service-account client placeholder; grant only the minimum roles needed and provision its secret securely.

O JSON inclui o usuário inicial `admin` (`admin@localhost`) com a role `infocell-admin`, sem senha definida. Após importar um realm novo, configure a senha no Admin Console ou por um fluxo de provisionamento seguro. O arquivo não contém credenciais reutilizáveis; não use esse usuário de desenvolvimento em produção.

This file does not create user passwords. Assign additional realm roles and organization membership through a separate, authenticated provisioning step.
