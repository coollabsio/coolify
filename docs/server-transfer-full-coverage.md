# Full-coverage server for transfer tests

Set up one source server with everything below, transfer it, then compare each item on the target.
Use values that are NOT the default, so that a lost value is visible after the transfer.

Use the kit in `scripts/transfer-test/` (see its README): it starts the source (A) and the target (B) and seeds A with a basic set of deployed resources.

Legend: ✅ `scripts/transfer-test/seed.sh`, `seed-extras.php`, or `seed-full.php` already creates it · ➕ add it · ⚠️ needs special infrastructure

`scripts/transfer-test/full-e2e.sh` creates the ✅ items, transfers the server, and compares every record and every container automatically.

The models come from `ServerTransferCoverage::TRANSFERRED` (`app/Services/ServerTransfer/ServerTransferCoverage.php`).
When you add a model or a setting to the transfer, add it to this file too.

## 1. Server (Server → General / Proxy)

| ✓ | What | Value to set |
|---|---|---|
| ✅ | Non-root SSH user | a dev VM with the `ubuntu-non-root` profile |
| ✅ | Name, description | any text |
| ➕ | Port | other than 22, if the VM allows it |
| ✅ | Proxy: redirect | redirect enabled + target URL |
| ✅ | Proxy: custom error page | custom HTML (`proxy_error_page`) |
| ➕ | Server role / build server | mark a second server as a build server |
| ⚠️ | Cloud provider link | Hetzner/Vultr/DigitalOcean/Hostinger server ID + cloud provider token (needs a real cloud server) |
| ⚠️ | Swarm | swarm manager / worker + swarm cluster + swarm destination (needs a swarm server) |

## 2. Server settings (Server → Advanced, Log drains, Sentinel/Metrics, Traffic, Cloudflare tunnel)

| ✓ | What | Value to set |
|---|---|---|
| ✅ | Wildcard domain | `http://{ip}.sslip.io` style, not empty |
| ✅ | Concurrent builds, dynamic timeout, deployment queue limit, connection timeout | 3, 1800, 5, 20 |
| ✅ | Server timezone | `Europe/Budapest` |
| ✅ | Docker cleanup | force cleanup on, frequency `0 4 * * *`, threshold 70, delete unused volumes on, delete unused networks on |
| ✅ | Disk usage notification | threshold 70, check frequency `*/30 * * * *`, interval 12 h |
| ✅ | Security > Patches | check frequency `daily`, automatic security updates on at `04:30` (the last check result is not moved; the target shows "never" until its first check) |
| ✅ | Terminal | terminal access off |
| ✅ | Image retention | "disable application image retention" on |
| ✅ | Labels / Traefik | generate exact labels on, Traefik certificate resolver `custom` |
| ✅ | Backup compression | CPU percentage 50 |
| ✅ | Metrics / Sentinel | metrics on, refresh rate 10 s, history 14 days, push interval 30 s |
| ✅ | Traffic analytics | on, top N 20, sample threshold, retention 1h=3 / 1d=60 days, IP mode `anonymized` |
| ✅ | GeoIP | on, MaxMind license key, refresh 14 days |
| ✅ | Log drains | New Relic (key + base URI), Highlight (project ID), Axiom (dataset + key), custom (config + parser), one of them enabled |
| ✅ | Cloudflare tunnel | tunnel on, tunnel ID, account ID, Cloudflare integration token selected, and the DNS record of the SSH hostname. Expected: the record moves with the server; the source keeps it only for a take back (not owned) |
| ➕ | Jump server flag | on (on a server that is only a jump host) |

## 3. Server-level records

| ✓ | What | Notes |
|---|---|---|
| ✅ | Default destination | created automatically |
| ✅ | Second standalone destination | extra Docker network, one resource on it |
| ✅ | Server shared variables | 2 variables, one multiline, one literal, one with a comment |
| ✅ | SSL certificates | CA certificate (created automatically) + a database with SSL on (creates a server certificate) |
| ✅ | GitHub runner config | labels, max runners 2, docker mode `dind`, image, CPU/memory limits, 3 timeouts, PR jobs off, dedicated on, uses the team GitHub App (see 4). Expected: on the target it is **disabled** and the checklist says to enable it |

## 4. Team credentials the server uses

| ✓ | What | Notes |
|---|---|---|
| ✅ | Server private key | |
| ✅ | Deploy key | a second private key used by an application (git over SSH) |
| ✅ | GitHub App (team, not system-wide) | with runner fields (`organization_self_hosted_runners`, `actions`, `webhook_events`, `runner_group_id`). Expected: checklist step for Webhook/Setup/Callback URL |
| ✅ | GitLab App (team) | used by one application. Expected: checklist step for the webhook URL |
| ✅ | S3 storage | the dev MinIO service (the VM must reach it), used by a database backup and a volume backup |
| ✅ | Cloudflare integration token | for DNS zones and the tunnel |
| ✅ | Secret manager token | Infisical, Doppler, or Vault |
| ⚠️ | Cloud provider token | only with a real cloud server |

## 5. Projects and environments

| ✓ | What | Notes |
|---|---|---|
| ✅ | 2 projects, 2 environments | for example production and staging |
| ✅ | Project / environment shared variables | the seed tries to create them; check that they exist |
| ✅ | Team shared variable | `TEAM_KEY`, used as `{{team.TEAM_KEY}}` in an application variable. Expected: created on the target |
| ✅ | Project description + icon (JPEG) | Expected: the icon is on the target |
| ✅ | Canvas positions | drag the nodes on the environment canvas |
| ✅ | Resource links | a link between 2 resources on this server, and a link to a resource on another server. Expected: the first moves, the second does not, and the export warns |
| ✅ | Environment with resources only on another server | Expected: it is not exported, and the prechecks show a `NOTE:` line |

## 6. Applications (one per build pack and source)

| ✓ | What | Notes |
|---|---|---|
| ✅ | Docker image app (whoami) | |
| ✅ | Dockerfile app | |
| ➕ | Nixpacks app, public GitHub repo | Expected: checklist step for manual webhooks |
| ✅ | Static app (`is_static`, `is_spa`) | |
| ➕ | Docker Compose app | raw compose on, one variable with an empty value (`preserve_compose_null`) |
| ➕ | App with GitHub App source, app with GitLab App source, app with deploy key | |
| ✅ | Domains | 2 domains, noindex domains, domain port overrides, preview URL template |
| ✅ | Health check | custom path, port, method, return code, interval, retries, start period |
| ✅ | Limits | memory, swap, swappiness, reservation, CPUs, cpuset, CPU shares |
| ➕ | Advanced | custom labels, custom docker run options, pre/post deployment command + container, manual webhook secrets |
| ✅ | Maintenance | maintenance mode on + custom maintenance page |
| ✅ | All application settings that are not the default | git submodules, LFS, shallow clone, auto deploy off, force HTTPS, debug, preview deployments on, PR deployments public, log drain, GPU (driver, count, device IDs, options), include timestamps, swarm-only worker nodes, build server, consistent container name, gzip off, strip prefix off, connect to Docker network, custom internal name, container label escape off, env sorting, read-only labels, preserve repository, disable build cache, use build secrets, stop grace period 30, rolling update drain time 12, inject build args off, include source commit, Docker images to keep 5, custom container name prefix, redact env values in logs, Coolify default variables off, Traefik certificate resolver |
| ✅ | Environment variables | normal, multiline, literal, shown once, build time only, runtime only, required, with comment, preview-only, and references to `{{team.*}}`, `{{project.*}}`, `{{environment.*}}`, `{{server.*}}` |
| ✅ | Persistent volume | the seed has one; add a host-path bind mount and preview suffix on |
| ✅ | File mount + directory mount | content, chown, chmod |
| ✅ | Volume backup | on the persistent volume: S3 on, stop during backup, all retention values, timeout, missing-backup days |
| ✅ | Scheduled tasks | the seed has one; add a disabled one with container + timeout |
| ✅ | Tag | |
| ✅ | Preview deployment | PR preview with domain port overrides + max restart count (needs the GitHub App, or create it in the database) |
| ✅ | Secret manager link | the app loads variables from the secret manager token |
| ✅ | Managed DNS record | owned record in the Cloudflare zone for the app domain. Expected: the target takes ownership (provider comment changes) |

## 7. Databases (one of each of the 9 types)

| ✓ | Type | Extra settings |
|---|---|---|
| ✅ | PostgreSQL | + init script, `postgres_conf`, host auth method, SSL on + mode |
| ✅ | MySQL | + `mysql_conf`, SSL |
| ✅ | MariaDB | + `mariadb_conf`, SSL |
| ✅ | MongoDB | + `mongo_conf`, SSL |
| ✅ | Redis | + `redis_conf`, SSL |
| ✅ | KeyDB, Dragonfly, ClickHouse | defaults are enough, plus the common settings below |
| ✅ | SQLite | database files list, volume link |

Common settings on at least one database: custom image, public port + public on + public port timeout, limits, custom docker run options, health check values, log drain, include timestamps, redact env values, Coolify default variables off, environment variables, persistent volume + volume backup, tags, secret manager link (if the type supports it).

Database backups (✅ one exists): add S3 on, dump all, databases list, local and S3 retention (amount, days, max storage), timeout, disable local backup, missing-backup days.

## 8. Services

| ✓ | What | Notes |
|---|---|---|
| ✅ | One-click service (uptime-kuma) | |
| ✅ | Custom compose service with an app and a database part | |
| ✅ | Service app settings | domain, gzip off, strip prefix off, force HTTPS, exclude from status, redirect, port overrides, log drain, custom image, Traefik resolver |
| ✅ | Service database settings | public port + public on, custom type |
| ✅ | Service level | connect to network, label escape off, redact env values, Coolify default variables off, maintenance mode + page, environment variables, scheduled task, tags, volume backup on a service volume |

## 9. After the transfer, check

- Transfer log: every check `OK`, the `NOTE:` line for the shared project.
- "After the transfer" checklist: GitHub App URLs, GitLab webhook, manual webhooks, GitHub runners. All URLs use the public URL of the target.
- Target: every item above has the same value. Runner config disabled. DNS records owned by the target.
- Source: the server is an archive, the runner config is disabled, and no DNS records are tracked. The archive refuses actions on the host, the cloud server (delete, power on), the Cloudflare tunnel, and DNS records.
- Delete the archive on the source: the containers, DNS records, and GitHub runners are not changed.
