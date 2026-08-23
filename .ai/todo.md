# Third-party secret manager integration (fetch-at-deploy)

Branch: third-party-secret-manager-integration

## Design (agreed with user)
- Coolify stores ONLY the integration token (encrypted) + link settings. Secret values are never persisted in the DB.
- Secrets are fetched in memory during deployment and merged into the generated `.env`.
- Local Coolify env vars override remote secrets on key conflict.
- Fetch failure fails the deployment with a clear error (no stale fallback in phase 1).
- Secret change => redeploy (webhook later), not sync.

## Phase 1 scope
Providers: doppler (service token), infisical (universal auth), vault (static token auth, KV v2).
Resources: Applications only.

## Tasks
- [x] Migration: add nullable `metadata` json to `integration_tokens`
- [x] Migration: create `secret_manager_links` (morph resourceable, integration_token_id FK cascade, settings json, is_runtime, is_buildtime)
- [x] Model: SecretManagerLink (+ fetchSecrets()); IntegrationToken metadata cast + links relation
- [x] Services: DopplerService, InfisicalService, VaultService (validate + fetchSecrets, timeouts like CloudflareTokenValidator)
- [x] Extend IntegrationTokenForm/Editor: providers doppler/infisical/vault, capability `secrets`, metadata fields, per-provider validation
- [x] Block token deletion while secret_manager_links exist
- [x] ApplicationDeploymentJob: merge remote secrets into runtime + buildtime env generation (local wins); fail deploy on fetch error
- [x] Livewire UI: Project/Shared/SecretManagerLinks on env var page (add/delete link, preview key names on demand)
- [x] Tests: token form (new providers), services (Http::fake), SecretManagerLink fetch, links Livewire component, deploy merge helper
- [x] Pint + run tests

## Review

Implemented fetch-at-deploy secret manager integration (Doppler, Infisical, Vault):

- DB: `integration_tokens.metadata` (json, non-secret config: base_url/client_id/namespace) + new `secret_manager_links` table (resource morph + token FK + settings json + runtime/buildtime flags). No secret values stored anywhere.
- Services: DopplerService (/v3/configs/config/secrets/download), InfisicalService (universal-auth login -> /api/v4/secrets, v3 raw fallback for older self-hosted), VaultService (KV v2, X-Vault-Token, optional namespace). Shared IntegrationTokenValidator dispatches per provider.
- Token UI: Keys & Tokens > Integration Tokens supports the 3 new providers with capability `secrets`, provider-specific fields, pre-save API validation, deletion blocked while links exist.
- Deploy: ApplicationDeploymentJob::remote_secrets() fetches once per deployment (cached), merges into runtime .env (dotenv-literal formatting, local vars win, COOLIFY_/SERVICE_ prefixes blocked), buildtime .env dict, and env_args. Fetch failure throws DeploymentException -> deployment fails with a clear log line.
- Link UI: "Secret managers" section under the app's Environment Variables page (add/remove link, runtime/buildtime flags, on-demand key-name preview that never stores values).
- Tests: 44 new tests pass (services, link model, job remote_secrets via reflection, dotenv formatting, token form, links component, delete guard). Unit suite baseline identical with/without changes (102 pre-existing env failures, unrelated). Pint clean, all blades compile.

Follow-ups (next phases): Doppler webhook -> auto-redeploy, Services support, Vault AppRole, stale-.env opt-in fallback, REST API for links.


# Iteration 2: reference model ({{secret.KEY}})

Agreed with user (brainstorm accepted):
- One secret source (API key + coordinates) per app, selected in the env variable view.
- Env vars are normal rows; values reference remote secrets: {{secret.KEY}} (aliases: {{vault.KEY}}, {{doppler.KEY}}, {{infisical.KEY}}). All aliases resolve against the app single source.
- Search remote keys + "Import all keys" (creates KEY={{secret.KEY}} rows, skips existing). Values never stored.
- Resolution ONLY in the deploy job (one cached bulk fetch); never in realValue/UI.
- Changing the API key does not re-check existing references; missing keys fail the deploy with a list.
- Bulk-inject model removed.

## Tasks
- [x] App\Support\RemoteSecretReferences (pattern, containsReference, referencedKeys, substitute)
- [x] Migration: secret_manager_links drop is_runtime/is_buildtime, unique per resource
- [x] Models: SecretManagerLink (flags out, importMissingReferences), Application morphOne secretManagerLink
- [x] EnvironmentVariable::isShared restricted to SHARED_VARIABLE_TYPES
- [x] Job: flat remote_secrets (fetch only when refs exist), substitution in runtime/buildtime/env_args, remove bulk-inject merges
- [x] UI: SecretManagerLinks -> source selector + key search/browse + import all + add single reference
- [x] Tests: references unit, substitution/missing-key via reflection, component rewrite, isShared regression
- [x] Pint + tests + baseline compare
- [x] Live dev test with real Doppler token (migrate, import, deploy, verify container + DB)

## Iteration 2 review

Implemented and live-tested the reference model:

- `App\Support\RemoteSecretReferences`: pattern for {{secret.KEY}} + provider aliases, key extraction, substitution, missing-key detection.
- `secret_manager_links`: one source per resource (unique constraint), runtime/buildtime flags dropped (now per-variable via normal env rows).
- Job: lazy cached fetch (only when a value references a secret), substitution in runtime .env (dotenv-literal), buildtime .env, env_args, railpack/nixpacks normalizer, Dockerfile ARG injection, and secrets hash. Missing key or fetch error -> DeploymentException with exact key + variable names. No source + references -> clear error.
- EnvironmentVariable::isShared restricted to SHARED_VARIABLE_TYPES via anchored regex (also fixes {{ project.x }} spaced form; {{secret.*}} no longer mislabeled shared).
- UI: "Secret manager" card on env page — source selector, Browse keys (names only), search filter, "Add as variable", "Import all keys" (via SecretManagerLink::importMissingReferences), remove source with warning.
- Tests: 57 secret-manager/token tests + 5 parser unit tests pass; Unit suite matches pre-existing baseline (102 env-related failures, unrelated); pint clean; blades compile.
- Live dev test (real Doppler service token, app 3 Dockerfile Example): import created 4 reference rows (values = {{secret.KEY}} strings only in DB), deploy fetched once ("Fetched 4 secrets from Doppler"), container had substituted values incl. composed value url-{{secret.SECRET}}-end, missing-key deploy failed with "Missing secret keys: DOES_NOT_EXIST (referenced by BROKEN)", cleanup redeploy healthy.

Follow-ups: Doppler webhook -> redeploy, Services support, Vault AppRole, key picker inside the Add-variable dialog, provider badge on reference rows.

## Iteration 2.1 (UX tweak)
- [x] Token selector: dropdown auto-saves on select (updatedIntegrationTokenUuid hook; provider change clears settings)
- [x] Provider settings fields auto-save on blur (wire:blur="saveSettings")
- [x] "Save source" button and editing state removed; Remove button kept next to the dropdown
- [x] Component tests updated (34 pass), pint clean, blades compile

## Iteration 2.2 (namespace rename)
- [x] Canonical reference namespace is {{vault.KEY}} (user request: differentiate from shared variables); {{doppler.KEY}} / {{infisical.KEY}} stay as aliases; {{secret.KEY}} removed and no longer parses
- [x] Import / Add-as-variable / UI texts / job error messages use {{vault.KEY}}
- [x] Tests updated (39 pass incl. negative assertion that {{secret.KEY}} is ignored)
- [x] Dev data migrated via tinker ({{secret.* -> {{vault.*), redeploy verified (container OK)

## Iteration 2.3 (UI bug fixes from user screenshots)
- [x] Key browser snippet rendered a raw Blade artifact ("{{vault.{{ $key }}}}") — now renders the exact reference, e.g. {{vault.DOPPLER_CONFIG}} (Blade escape fixed via PHP string concat; regression-asserted in component test)
- [x] Env value autocomplete ({{ typing) now offers a "vault" scope whenever the app has a secret manager source; keys are lazy-fetched from the provider on first use via $wire.fetchSecretManagerKeys() (names only, never persisted)
- [x] Autocomplete now also works in the edit-variable modal: Show (and Add) use the new HasSecretManagerAutocomplete trait and pass hasVaultSource to env-var-input; previously the dropdown never appeared when no shared variables existed
- [x] 41 tests pass; pint clean; blades compile. Browser click-through not verified (Chrome extension permission unavailable) — user to smoke-test.
