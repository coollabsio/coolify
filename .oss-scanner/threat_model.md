# Threat model

Coolify is a self-hosted PaaS and deploy control plane (an alternative to Heroku, Netlify, or Vercel). An operator runs one Coolify instance. That instance stores team credentials and, over SSH, tells Docker on the operator's servers to build and run applications, databases, and one-click services.

A bug in the control plane is not limited to the Coolify host. It can expose SSH keys, deploy secrets, and database credentials, or run attacker-controlled commands on every server the instance manages.

The `.oss-scanner` image is an offline audit environment (source, Composer including require-dev, built Vite assets). It is not the production runtime in `docker/production/Dockerfile`. Production-only daemons (nginx, cloudflared, the MinIO client, the terminal server) are still in this repository and in scope when the vulnerable code is here. Do not require those daemons, or a live customer server, to demonstrate a bug.

## What this project does and where untrusted input enters

Coolify's hierarchy is Team, then Project, then Environment, then a resource (application, service, or standalone database). Servers are team-scoped and shared across environments. The primary UI is Livewire (`app/Livewire/`, `resources/views/livewire/`). There is also a Sanctum API under `routes/api.php` and unauthenticated webhook routes in `routes/webhooks.php`.

Treat these inputs as adversarial:

- **Git webhooks.** `routes/webhooks.php` and `app/Http/Controllers/Webhook/{Github,Gitlab,Bitbucket,Gitea}.php`. Push and pull-request payloads can start builds and preview deployments. Signed routes and manual routes (`/source/*/events/manual`) both matter. A signature bypass, a comparison that is not constant-time, or a manual secret that is empty, shared, or not bound to the target application can deploy code the team did not intend. Fork pull requests are a trust boundary (see the GitHub controller's fork handling). Existing coverage: `tests/Feature/Webhook/WebhookHmacTest.php`, `tests/Feature/GithubWebhookTest.php`, and the other `tests/Feature/Webhook/` and `*Webhook*` tests.
- **Stripe webhooks.** `app/Http/Controllers/Webhook/Stripe.php` and `tests/Feature/StripeWebhookSecurityTest.php`. Forging or replaying these changes billing state on Coolify Cloud.
- **User Dockerfiles, Compose files, build packs, and custom commands.** Applications and services store Dockerfiles, Compose YAML, start commands, build commands, health checks, and pre/post deployment commands. Those strings are rendered and executed on customer servers. Parser entry points: `applicationParser()` and `serviceParser()` in `bootstrap/helpers/parsers.php`, `updateCompose()` in `bootstrap/helpers/services.php`, and the deployment job `app/Jobs/ApplicationDeploymentJob.php`. Nixpacks is deprecated; prefer the Dockerfile and Railpack paths.
- **Domains and proxy configuration.** User-controlled FQDNs become Traefik labels and generated proxy config. Injection into a label, a generated nginx/Traefik snippet, or a shell argument is in scope. See `app/Livewire/Server/Proxy/` and `tests/Unit/ProxyConfigurationSecurityTest.php`.
- **Environment variables and secrets.** Per-resource, shared, and team variables, including secret values interpolated into containers and remote commands. See `app/Support/RemoteSecretReferences.php` and the environment-variable Livewire components and policies. Leaking or crossing these between teams is a product bug, not a documentation issue.
- **Uploaded files.** Chunked database backup uploads (`app/Http/Controllers/UploadController.php`, up to 10 GiB) and backup restore paths. Filename and archive handling (path traversal, zip slip, command injection in the restore script) is in scope. See `tests/Feature/DatabaseImportCommandInjectionTest.php`.
- **API tokens.** Laravel Sanctum tokens with abilities such as read, write, deploy, and `write:sensitive`. Middleware: `app/Http/Middleware/ApiAbility.php`, `ApiSensitiveData.php`, `ApiAllowed.php`, and `EnsureTokenBelongsToCurrentTeamMember.php` (`api.token.team` in `app/Http/Kernel.php`). A read token that can deploy, write, or read secrets is a vulnerability. Controllers live in `app/Http/Controllers/Api/` and validate inline rather than with Form Request classes.
- **OAuth, OIDC, and Git-provider callbacks.** Fortify (`app/Actions/Fortify/`, `app/Providers/FortifyServiceProvider.php`) and Socialite providers (GitHub, GitLab, Google, Microsoft, Discord, Authentik, Clerk, Zitadel, Infomaniak, plus generic OIDC in `app/Auth/Oidc/`). Authorization-code mixups, open redirects, and linking an identity to the wrong team or user are in scope.
- **Multi-tenant boundaries.** Policies are in `app/Policies/`. A user can belong to several teams and can switch the session team in another tab while a page stays open. After a component, action, or controller has loaded a resource, authorization and related-record queries must use that resource's team, not `currentTeam()`, `isAdmin()`, or `isMember()` (those read the session team). A member of the resource's team who is the owner of a different team, with the session sitting on the other team, is the interesting case. Team id `0` and user id `0` are instance sentinels (root team, root user), not normal tenants. Granting a normal tenant those privileges is critical.
- **SSH, sudo, and the terminal.** Remote commands go through `bootstrap/helpers/remoteProcess.php` and `parseCommandsByLineForSudo()` in `bootstrap/helpers/sudo.php`. Servers may use a non-root SSH user, so pipelines, redirects, substitutions, and `sh -c` / `bash -c` are injection-sensitive. The browser terminal is `app/Livewire/Terminal/` plus `docker/coolify-terminal/`. An auth bypass there is a shell on the server.
- **Outbound HTTP from the control plane (SSRF).** Webhook notification URLs, Git provider API base URLs, OIDC discovery, S3 endpoints, Sentinel metrics, version checks, and similar. `app/Rules/SafeWebhookUrl.php` and `app/Rules/SafeExternalUrl.php` are the intended guards. Loopback, link-local, and cloud metadata addresses must stay blocked. Redirects and DNS rebinding are the usual bypasses. Self-hosted git sources may intentionally use private networks; Coolify Cloud should not. See `tests/Unit/SafeWebhookUrlTest.php`.
- **Cloud and backup credentials.** Hetzner, DigitalOcean, Vultr, and Hostinger tokens, S3 storage credentials, GitHub/GitLab/Gitea app private keys. Models and Livewire editors live under `app/Livewire/Security/` and `app/Http/Controllers/Api/`.

## Components that matter most / least

Matter most:

- Authorization on Livewire actions, API routes, downloads, and anything that returns environment variables, private keys, or cloud tokens (`app/Policies/`, `app/Http/Controllers/Api/`, `app/Livewire/Security/PrivateKey/`, `app/Models/PrivateKey.php`).
- The deploy pipeline and remote command construction (`app/Jobs/ApplicationDeploymentJob.php`, `bootstrap/helpers/remoteProcess.php`, `bootstrap/helpers/sudo.php`, `bootstrap/helpers/docker.php`, `bootstrap/helpers/parsers.php`).
- Webhook authentication (`app/Http/Controllers/Webhook/`, `tests/Feature/Webhook/WebhookHmacTest.php`).
- SSRF guards (`app/Rules/SafeWebhookUrl.php`, `app/Rules/SafeExternalUrl.php`).
- Session and token handling (Fortify, Sanctum, `ApiSensitiveData`).

Matter, but only with a reachable Coolify bug:

- `vendor/` and `node_modules/`. A CVE in a dependency is in scope when Coolify passes attacker-controlled input into the vulnerable behavior. "This package has a CVE" with no reachable path is out of scope.
- The production image extras under `docker/production/` and `docker/coolify-terminal/`.

Matter least:

- Visual polish, copy, and layout.
- Deprecated Nixpacks behavior, except where it still executes attacker-controlled commands.
- The `.oss-scanner` image itself. It is a build recipe for the audit, not a deployed attack surface.

## High-value assets

- SSH private keys to customer servers (`PrivateKey`, `storage/app/ssh`).
- Deploy secrets and environment-variable values.
- Database credentials for managed databases and for Coolify's own database (the instance Postgres sentinel, id `0`).
- GitHub, GitLab, and Gitea app credentials and OAuth tokens.
- Cloud provider API tokens and S3 credentials.
- Stripe keys and subscription state on Coolify Cloud.
- Instance admin: root user id `0`, root team id `0`, Horizon access (`app/Providers/HorizonServiceProvider.php`), and instance settings (`InstanceSettings` id `0`).

## In scope

- Authorization bypass, including cross-team reads or writes and member actions that should be admin-only (credentials, billing, instance settings, private keys).
- Remote code execution via the deploy pipeline, a build pack, Compose, a health check, a scheduled task, or the terminal.
- Secret leakage (API responses, logs, Livewire snapshots, HTML, broadcast channels, error pages).
- SSRF from the control plane, especially to cloud metadata or to another tenant's network.
- Multi-tenant isolation failures, including the session-team versus resource-team mixup described above.
- Webhook signature bypass and manual-webhook secret bypass.
- Command injection in server, proxy, backup, and agent command construction. Useful existing tests: `tests/Unit/SshCommandInjectionTest.php`, `tests/Unit/UnmanagedContainerCommandInjectionTest.php`, `tests/Unit/LogDrainCommandInjectionTest.php`, `tests/Unit/HealthCheckCommandInjectionTest.php`, `tests/Feature/Security/CommandInjectionSecurityTest.php`, `tests/Feature/GetLogsCommandInjectionTest.php`.

## Out of scope / lower priority

- Pure UI polish, styling, and copy.
- Dependency CVEs in `vendor/` or `node_modules/` unless Coolify code can reach them with attacker-controlled input.
- Denial of service that only burns CPU or disk on the Coolify host and does not cross an authorization boundary. Rate that medium, not critical. An unauthenticated webhook flood that only consumes CPU is medium.
- Missing security headers with no demonstrated impact.
- The committed `.env.testing` `APP_KEY` and other fixture secrets under `tests/`. Those are public test fixtures.
- The existence of sentinel rows with `id = 0`. They are intentional. A bug that lets a normal tenant act as id `0` is in scope.
- Suggestions to add a `TRUSTED_PROXIES` setting or to change `TrustProxies` to trust a proxy by default. That has broken supported installs. Fix IP-based rate limits and allow-lists at the call site.

## How you rate severity

- **Critical.** Remote unauthenticated RCE. Unauthenticated or cross-tenant access to SSH keys, deploy secrets, database credentials, or cloud tokens. A member who can read another team's private key, or trigger a deploy or shell on another team's server, is critical.
- **Critical or high.** Authenticated privilege escalation to another tenant, to instance admin (root user / team id `0`), or to server SSH. Escalation from member to admin inside the same team, without reaching another tenant or SSH, is high.
- **High.** Post-auth SQL injection, or XSS that can steal a session, an API token, or a secret. Stored XSS on a page an admin of the same team will open is high. Self-XSS is low.
- **Medium or high.** Information disclosure without authentication. A leaked server IP or project name is medium. A leaked environment value, private key, session cookie, or API token is high even when the bug is "only" a leak.
- **Medium.** Denial of service that only consumes CPU, memory, or disk on the Coolify host and does not cross an authorization boundary.
- **Low.** Hardening with no demonstrated security impact. Speculative dependency upgrades with no reachable path.

## How to exercise it

The audit image has PHP 8.4, the extensions Coolify needs (`pdo_pgsql`, `pdo_mysql`, `pdo_sqlite`, `redis`, `gd`, `zip`, `bcmath`, `intl`, `sockets`, `pcntl`), Composer require-dev, and a Vite production build. `.env` is the testing fixture: `DB_CONNECTION=testing` is sqlite `:memory:` (`config/database.php`), and `phpunit.xml` forces an array cache, the sync queue, and array mail. Postgres and Redis are not running.

- Boot the app with `php artisan` (`php artisan --version`, `php artisan route:list`, `php artisan test`).
- Pest tests live under `tests/`. Prefer extending an existing security test over a live multi-server setup. `tests/Unit/` is the usual place for parser, validation, and command-construction bugs. `tests/Feature/` boots HTTP and Livewire. `tests/v4/Browser/` needs Playwright browsers, which this image does not download.
- Remote SSH and Docker are faked in tests via `tests/Support/RemoteProcessTestHelpers.php` and `Illuminate\Support\Facades\Process`. A Pest test that feeds a malicious Dockerfile, Compose file, domain, env value, webhook body, or server field is the useful proof. Show the command or query that would be sent, not a connection to a real host.
- Livewire components are invoked with `Livewire::test(...)`. API routes are invoked with `$this->postJson('/api/v1/...')` and a Sanctum token. Webhooks are HTTP POSTs to the paths in `routes/webhooks.php`.
- Feature tests that need a database should use the testing connection. Create `InstanceSettings` with `id = 0` when the code reads instance settings. Do not point tests at a networked database.

## Reports and patches

- One report per root cause. If the same missing policy check is reachable from a Livewire action and an API route, file one report and list both entry points, unless the fixes are independent.
- Include the precondition: unauthenticated, same-team member, same-team admin, or cross-team. Say which asset is reached.
- Prefer a minimal patch and a Pest regression test that fails before the patch and passes after it. A merge-ready test is more useful than a long exploit script.
- Do not propose `TRUSTED_PROXIES` changes. Do not treat sentinel `id = 0` rows as accidental data.
