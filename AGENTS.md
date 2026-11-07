# Project instructions

These instructions apply to the entire repository. Keep this file and `CLAUDE.md` versioned so OpenAI Codex and Claude Code share the same project context.

## Project

Infocell Gestão is a Laravel 12 application with a Vue 3 + TypeScript frontend, Tailwind CSS 4, PostgreSQL/SQLite support, OpenAPI contracts, Keycloak login, and multi-branch inventory. Read `README.md` for setup and domain status before changing behavior.

## Working agreements

- Inspect the relevant implementation, tests, routes, and migrations before editing. Follow existing Laravel, Vue, and TypeScript conventions; keep changes scoped.
- Preserve unrelated user changes. Do not reset, stage, commit, or rewrite history unless explicitly requested. Never expose `.env`, credentials, or secrets.
- Keep API behavior, OpenAPI schema, and generated TypeScript client in sync. `resources/js/api/generated/` is generated: change its source (routes/controllers/schema or generator configuration), then regenerate; do not hand-edit generated files.
- Add or update tests for behavior changes. Keep business rules and authorization on the server; API v1 routes are protected by `auth:sanctum`, and the Vue app uses the authenticated web session.
- Use migrations for database schema changes. Consider existing installations as well as fresh databases; do not assume editing an old migration updates deployed databases.
- Treat stock movements, sales, and fiscal documents as transactional domain operations. The current fiscal-document endpoint only records a `pending` document; it does not issue or authorize NF-e/NFC-e. Do not describe or implement it as production fiscal issuance without a real, tested provider integration.
- Do not claim production readiness for authentication/authorization, branch scoping, multi-tenancy, or fiscal issuance unless those controls have been implemented and tested. See the production checklist in `README.md`.
- User-facing application text is in Portuguese; keep new UI copy consistent with the surrounding screens.
- For every UI change, read and follow the root `DESIGN.md`; it is the shared design system for all agents. Keep new screens/components consistent with its tokens and patterns, and update it when a deliberate design-system change is approved.
- Every option-choice field in Vue UI (forms and filters, single or multiple) must use the shared `resources/js/components/SearchableCombobox.vue`. Do not introduce native `<select>` elements in Vue templates; preserve labels, values, optional empty choices, disabled/required states, edit values, and accessible interaction when migrating.

## Useful commands

- `composer test` — Laravel/PHP tests (also clears Laravel config first).
- `php artisan test` — run the PHP test suite directly.
- `npm run test` — frontend Vitest tests.
- `npm run build` — production frontend build and type checks performed by the project build.
- `npm run openapi:export` — export the OpenAPI contract (requires the Laravel app/server as described in `README.md`).
- `npm run api:generate` — regenerate the TypeScript API client from the configured OpenAPI spec.

Run the narrow relevant tests while iterating, then the appropriate full test/build checks before reporting completion. State clearly which checks were run and any that could not run.
