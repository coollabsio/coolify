# Coolify UI Audit

## Form Card Class Hierarchy

Grepable CSS utility classes for structural levels (defined in `resources/css/utilities.css`):

| Class | Purpose | Usage |
|-------|---------|-------|
| `form-page` | Page-level container wrapping all cards | `<div class="form-page">` — provides `flex flex-col gap-4` |
| `form-card` | Main card (h2 + form-section-title + Save + primary fields). Includes `flex flex-col gap-4 max-w-4xl`. | `<div class="form-card">` |
| `form-subsection` | H3 sub-section card within a page. Includes `flex flex-col gap-10 max-w-4xl`. | `<div class="form-subsection">` |
| `form-section` | **Deprecated** — no remaining usages in views. CSS kept for safety. | Do not use in new code |
| `form-section-title` | Title bar (h2/h3 + action buttons) — unchanged | `<div class="form-section-title">` |

### Spacing Standards

| Context | Standard |
|---------|----------|
| Between sibling cards | `gap-4` (via `form-page` or form/container class) |
| Inside form-card (sections) | `gap-4` (baked into CSS utility) |
| Inside form-subsection (fields) | `gap-10` (baked into CSS utility) |
| Inside standalone field containers | `gap-8` (inline class on flex-col divs/forms) |
| H3 padding | None — card's `p-6` handles it |
| Width override for wide content | Add `max-w-none` inline (textareas, code editors, grids) |
| Bordered item forms (env vars, storages) | Add `rounded-lg` to match card rounding |
| Title + action buttons | Always use `form-section-title` (provides `flex items-center justify-between gap-4`). Wrap multiple buttons in `<div class="flex items-center gap-2">`. |
| Data tables | Wrap in `form-card max-w-none` — tables need full width |

### Migration Status

| File | form-card | form-subsection | form-page | Legacy form-section |
|------|:---------:|:---------------:|:---------:|:-------------------:|
| application/general.blade.php | 1 | 7 | 1 | — |
| application/advanced.blade.php | 1 | 7 | 1 | — |
| application/source.blade.php | 1 | 2 | 1 | — |
| application/previews.blade.php | — | 1 | — | — |
| database/mysql/general.blade.php | 1 | 4 | 1 | — |
| database/mariadb/general.blade.php | 1 | 4 | 1 | — |
| database/mongodb/general.blade.php | 1 | 4 | 1 | — |
| database/redis/general.blade.php | 1 | 4 | 1 | — |
| database/postgresql/general.blade.php | 1 | 5 | 1 | — |
| database/keydb/general.blade.php | 1 | 4 | 1 | — |
| database/dragonfly/general.blade.php | 1 | 4 | 1 | — |
| database/clickhouse/general.blade.php | 1 | 3 | 1 | — |
| database/import.blade.php | — | 4 | — | — |
| database/backup-edit.blade.php | — | 2 | — | — |
| server/advanced.blade.php | 1 | 2 | — | — |
| server/docker-cleanup.blade.php | 1 | 3 | — | — |
| server/proxy.blade.php | 4 | 2 | — | — |
| server/log-drains.blade.php | 1 | 3 | — | — |
| server/show.blade.php | 1 | 1 | — | — |
| server/cloudflare-tunnel.blade.php | 1 | 4 | — | — |
| server/create.blade.php | — | 1 | — | — |
| settings/advanced.blade.php | 1 | 4 | — | — |
| shared/webhooks.blade.php | 1 | 1 | — | — |
| shared/environment-variable/all.blade.php | 1 | 2 | — | — |
| shared/tags.blade.php | 1 | 2 | — | — |
| notifications/discord.blade.php | 1 | 4 | — | — |
| notifications/email.blade.php | — | 6 | — | — |
| notifications/pushover.blade.php | 1 | 4 | — | — |
| notifications/slack.blade.php | 1 | 4 | — | — |
| notifications/telegram.blade.php | 1 | 4 | — | — |
| notifications/webhook.blade.php | 1 | 4 | — | — |
| settings/index.blade.php | 1 | — | — | — |
| settings/updates.blade.php | 1 | — | — | — |
| settings-email.blade.php | 1 | 2 | — | — |
| settings-oauth.blade.php | 1 | — | — | — |
| profile/index.blade.php | 3 | — | — | — |
| team/index.blade.php | 1 | — | — | — |
| team/member/index.blade.php | 2 | — | — | — |
| team/admin-view.blade.php | 1 | — | — | — |
| security/private-key/index.blade.php | 1 | — | — | — |
| security/private-key/show.blade.php | 1 | — | — | — |
| security/cloud-init-scripts.blade.php | 1 | — | — | — |
| security/api-tokens.blade.php | 2 | — | — | — |
| security/cloud-provider-tokens.blade.php | 2 | — | — | — |
| server/sentinel.blade.php | 1 | — | — | — |
| server/ca-certificate/show.blade.php | 1 | — | — | — |
| server/security/terminal-access.blade.php | 1 | — | — | — |
| server/swarm.blade.php | 1 | — | — | — |
| project/shared/resource-operations.blade.php | 1 | — | — | — |
| project/shared/resource-limits.blade.php | 1 | — | — | — |
| project/shared/health-checks.blade.php | 1 | — | — | — |
| project/shared/metrics.blade.php | 1 | — | — | — |
| project/application/rollback.blade.php | 1 | — | — | — |
| project/application/preview/form.blade.php | 1 | — | — | — |
| project/application/swarm.blade.php | 1 | — | — | — |
| project/service/stack-form.blade.php | 1 | — | — | — |
| storage/form.blade.php | 1 | — | — | — |
| project/service/storage.blade.php | 1 | — | — | — |
| tags/show.blade.php | 1 | — | — | — |
| source/github/change.blade.php | 1 | — | — | — |

All **32 legacy `form-section` files** have been migrated to `form-card`/`form-subsection`. Zero `form-section` usages remain in views.

---

## Page Types

| Type | Description |
|------|-------------|
| **Simple** | Title + description + content (no sub-navigation) |
| **Tabs** | Horizontal tab navbar shared across sibling pages |
| **Sidebar** | Left sidebar sub-menu within a tab group |
| **Tabs+Sidebar** | Both horizontal tabs and a sidebar sub-menu |
| **Breadcrumb+Tabs+Sidebar** | Resource pages with project→env→resource breadcrumbs, tabs, and config sidebar |

---

## 1. All Pages

### Top-Level Pages (no sub-navigation)

| Page | Type | Title | Desc | Actions | Path |
|------|------|-------|:----:|---------|------|
| Dashboard | Simple | Dashboard | ✅ | — | `livewire/dashboard.blade.php` |
| Projects | Simple | Projects | ✅ | + Add | `livewire/project/index.blade.php` |
| Servers | Simple | Servers | ✅ | + Add | `livewire/server/index.blade.php` |
| Sources | Simple | Sources | ✅ | + Add | `source/all.blade.php` |
| Destinations | Simple | Destinations | ✅ | + Add | `livewire/destination/index.blade.php` |
| S3 Storages | Simple | S3 Storages | ✅ | + Add | `livewire/storage/index.blade.php` |
| Shared Variables | Simple | Shared Variables | ✅ | — | `livewire/shared-variables/index.blade.php` |
| Tags | Simple | Tags | ✅ | — | `livewire/tags/show.blade.php` |
| Terminal | Simple | Terminal | ✅ | — | `livewire/terminal/index.blade.php` |
| Profile | Simple | Profile | ✅ | — | `livewire/profile/index.blade.php` |
| Subscription | Simple | Subscriptions | ❌ | — | `livewire/subscription/index.blade.php` |
| Admin | Simple | Admin Dashboard | ❌ | Go back to root | `livewire/admin/index.blade.php` |
| Onboarding | Wizard | Welcome to Coolify | ✅ | Step buttons | `livewire/boarding/index.blade.php` |

### Project Pages

| Page | Type | Nav | Title | Desc | Actions | Path |
|------|------|-----|-------|:----:|---------|------|
| Environments | Simple | — | Environments | ✅ | + Add, Delete | `livewire/project/show.blade.php` |
| Resources | Simple | — | Resources | ✅ | + New, Clone, Delete | `livewire/project/resource/index.blade.php` |
| New Resource | Simple | — | Select resource | ✅ | Env select | `livewire/project/new/select.blade.php` |
| Edit Project | Simple | — | {Project name} | ✅ | Save, Delete | `livewire/project/edit.blade.php` |
| Edit Environment | Simple | — | {Environment name} | ✅ | Save, Delete | `livewire/project/environment-edit.blade.php` |
| New App (GitHub) | Simple | — | Create a new Application | ✅ | + Add GitHub App | `livewire/project/new/github-private-repository.blade.php` |
| New App (Docker Image) | Simple | — | Create a new Application | ✅ | Save | `livewire/project/new/docker-image.blade.php` |
| New App (Dockerfile) | Simple | — | Create a new Application | ✅ | Save | `livewire/project/new/simple-dockerfile.blade.php` |
| New Service (Compose) | Simple | — | Create a new Service | ✅ | Save | `livewire/project/new/docker-compose.blade.php` |

### Settings Pages (navbar: `x-settings.navbar`)

| Page | Type | Sidebar | Title | Desc | Actions | Path |
|------|------|---------|-------|:----:|---------|------|
| General | Tabs+Sidebar | `x-settings.sidebar` | General | ✅ | Save | `livewire/settings/index.blade.php` |
| Advanced | Tabs+Sidebar | `x-settings.sidebar` | Advanced | ✅ | Save | `livewire/settings/advanced.blade.php` |
| Updates | Tabs+Sidebar | `x-settings.sidebar` | Updates | ✅ | Save | `livewire/settings/updates.blade.php` |
| Backup | Tabs | — | Backup | ✅ | Save | `livewire/settings-backup.blade.php` |
| Transactional Email | Tabs | — | Transactional Email | ✅ | Save, Test | `livewire/settings-email.blade.php` |
| OAuth | Tabs | — | Authentication | ✅ | Save | `livewire/settings-oauth.blade.php` |

### Notification Pages (navbar: `x-notification.navbar`)

| Page | Type | Title | Desc | Actions | Path |
|------|------|-------|:----:|---------|------|
| Email | Tabs | Email | ❌ | Save, Test | `livewire/notifications/email.blade.php` |
| Discord | Tabs | Discord | ❌ | Save, Test | `livewire/notifications/discord.blade.php` |
| Telegram | Tabs | Telegram | ❌ | Save, Test | `livewire/notifications/telegram.blade.php` |
| Slack | Tabs | Slack | ❌ | Save, Test | `livewire/notifications/slack.blade.php` |
| Pushover | Tabs | Pushover | ❌ | Save, Test | `livewire/notifications/pushover.blade.php` |
| Webhook | Tabs | Webhook | ❌ | Save, Test | `livewire/notifications/webhook.blade.php` |

### Security Pages (navbar: `x-security.navbar`)

| Page | Type | Title | Desc | Actions | Path |
|------|------|-------|:----:|---------|------|
| Private Keys | Tabs | Private Keys | ❌ | + Add | `livewire/security/private-key/index.blade.php` |
| Private Key Detail | Tabs | Private Key | ❌ | Save, Delete | `livewire/security/private-key/show.blade.php` |
| Cloud Provider Tokens | Tabs | Cloud Provider Tokens | ✅ | — | `livewire/security/cloud-provider-tokens.blade.php` |
| Cloud-Init Scripts | Tabs | Cloud-Init Scripts | ✅ | + Add | `livewire/security/cloud-init-scripts.blade.php` |
| API Tokens | Tabs | API Tokens | ✅ | — | `livewire/security/api-tokens.blade.php` |

### Team Pages (navbar: `x-team.navbar`)

| Page | Type | Title | Desc | Actions | Path |
|------|------|-------|:----:|---------|------|
| General | Tabs | General | ✅ | Save | `livewire/team/index.blade.php` |
| Members | Tabs | Members | ✅ | — | `livewire/team/member/index.blade.php` |
| Admin View | Tabs | Admin View | ✅ | — | `livewire/team/admin-view.blade.php` |

### Server Pages (navbar: `livewire:server.navbar`, sidebar: `x-server.sidebar`)

| Page | Type | Sidebar | Title | Desc | Actions | Path |
|------|------|---------|-------|:----:|---------|------|
| General | Tabs+Sidebar | `x-server.sidebar` | General | ✅ | Save | `livewire/server/show.blade.php` |
| Advanced | Tabs+Sidebar | `x-server.sidebar` | Advanced | ✅ | Save | `livewire/server/advanced.blade.php` |
| Sentinel | Tabs+Sidebar | `x-server.sidebar` | Sentinel | ✅ | Save | `livewire/server/sentinel.blade.php` |
| Private Key | Tabs+Sidebar | `x-server.sidebar` | Private Key | — | Save | `livewire/server/private-key.blade.php` |
| Hetzner Token | Tabs+Sidebar | `x-server.sidebar` | Hetzner Token | ✅ | + Add, Validate | `livewire/server/cloud-provider-token/show.blade.php` |
| CA Certificate | Tabs+Sidebar | `x-server.sidebar` | CA Certificate | ❌ | Save, Delete | `livewire/server/ca-certificate/show.blade.php` |
| Cloudflare Tunnel | Tabs+Sidebar | `x-server.sidebar` | Cloudflare Tunnel | ✅ | Save | `livewire/server/cloudflare-tunnel.blade.php` |
| Docker Cleanup | Tabs+Sidebar | `x-server.sidebar` | Docker Cleanup | ✅ | Save | `livewire/server/docker-cleanup.blade.php` |
| Destinations | Tabs+Sidebar | `x-server.sidebar` | Destinations | — | — | `livewire/server/destinations.blade.php` |
| Log Drains | Tabs+Sidebar | `x-server.sidebar` | Log Drains | ✅ | Save | `livewire/server/log-drains.blade.php` |
| Metrics | Tabs+Sidebar | `x-server.sidebar` | Metrics | ✅ | — | `livewire/server/charts.blade.php` |
| Swarm | Tabs+Sidebar | `x-server.sidebar` | Swarm | ✅ | Save | `livewire/server/swarm.blade.php` |
| Danger | Tabs+Sidebar | `x-server.sidebar` | Delete Server | — | Delete | `livewire/server/delete.blade.php` |
| Proxy Config | Tabs+Sidebar | `x-server.sidebar-proxy` | Configuration | ✅ | Save | `livewire/server/proxy.blade.php` |
| Proxy Dynamic | Tabs+Sidebar | `x-server.sidebar-proxy` | Dynamic Configurations | ✅ | + Add | `livewire/server/proxy/dynamic-configurations.blade.php` |
| Proxy Logs | Tabs+Sidebar | `x-server.sidebar-proxy` | Logs | — | — | `livewire/server/proxy/logs.blade.php` |
| Resources | Tabs | — | Resources | ✅ | — | `livewire/server/resources.blade.php` |
| Server Patching | Tabs+Sidebar | `x-server.sidebar-security` | Server Patching | ✅ | Save, Update | `livewire/server/security/patches.blade.php` |
| Terminal Access | Tabs+Sidebar | `x-server.sidebar-security` | Terminal Access | ✅ | Save | `livewire/server/security/terminal-access.blade.php` |

### Application Pages (navbar: `livewire:project.application.heading`, breadcrumbs: `x-resources.breadcrumbs`)

| Page | Type | Sidebar | Title | Desc | Actions | Path |
|------|------|---------|-------|:----:|---------|------|
| General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/application/general.blade.php` |
| Advanced | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Advanced | ✅ | — | `livewire/project/application/advanced.blade.php` |
| Swarm | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Swarm Configuration | ❌ | Save | `livewire/project/application/swarm.blade.php` |
| Env Variables | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Environment Variables | ✅ | + Add, Dev view | `livewire/project/shared/environment-variable/all.blade.php` |
| Storage | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Storages | ✅ | + Add | `livewire/project/service/storage.blade.php` |
| Source | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Source | ✅ | Save | `livewire/project/application/source.blade.php` |
| Scheduled Tasks | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Scheduled Tasks | ❌ | + Add | `livewire/project/shared/scheduled-task/all.blade.php` |
| Webhooks | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Webhooks | ❌ | — | `livewire/project/shared/webhooks.blade.php` |
| Preview Deployments | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Preview Deployments | ✅ | Save | `livewire/project/application/preview/form.blade.php` |
| Healthchecks | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Healthchecks | ✅ | Save | `livewire/project/shared/health-checks.blade.php` |
| Rollback | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Rollback | ✅ | Reload | `livewire/project/application/rollback.blade.php` |
| Resource Limits | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Resource Limits | ✅ | Save | `livewire/project/application/resource-limits.blade.php` |
| Resource Operations | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Resource Operations | ✅ | Save | `livewire/project/application/resource-operations.blade.php` |
| Metrics | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Metrics | ✅ | — | `livewire/project/shared/metrics.blade.php` |
| Tags | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Tags | ❌ | + Add | `livewire/project/shared/tags.blade.php` |
| Deployments | BC+Tabs | — | Deployments | ❌ | Pagination | `livewire/project/application/deployment/index.blade.php` |
| Logs | BC+Tabs | — | Logs | — | — | `livewire/project/application/logs.blade.php` |

### Database Pages (navbar: `livewire:project.database.heading`, breadcrumbs: `x-resources.breadcrumbs`)

| Page | Type | Sidebar | Title | Desc | Actions | Path |
|------|------|---------|-------|:----:|---------|------|
| PostgreSQL General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/database/postgresql/general.blade.php` |
| MySQL General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/database/mysql/general.blade.php` |
| MariaDB General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/database/mariadb/general.blade.php` |
| MongoDB General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/database/mongodb/general.blade.php` |
| Redis General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ✅ | Save | `livewire/project/database/redis/general.blade.php` |
| KeyDB General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ❌ | Save | `livewire/project/database/keydb/general.blade.php` |
| Dragonfly General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ❌ | Save | `livewire/project/database/dragonfly/general.blade.php` |
| Clickhouse General | BC+Tabs+Sidebar | `.sub-menu-wrapper` | General | ❌ | Save | `livewire/project/database/clickhouse/general.blade.php` |
| Backups | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Scheduled Backups | ❌ | + Add | `livewire/project/database/backup/index.blade.php` |
| Backup Executions | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Executions | ❌ | Pagination | `livewire/project/database/backup-executions.blade.php` |

### Service Pages (navbar: `livewire:project.service.heading`, breadcrumbs: `x-resources.breadcrumbs`)

| Page | Type | Sidebar | Title | Desc | Actions | Path |
|------|------|---------|-------|:----:|---------|------|
| Stack Form | BC+Tabs+Sidebar | `.sub-menu-wrapper` | Service Stack | ✅ | Save | `livewire/project/service/stack-form.blade.php` |
| Database Backups | BC+Tabs+Sidebar | `x-service-database.sidebar` | Scheduled Backups | ❌ | + Add | `livewire/project/service/database-backups.blade.php` |

### Shared Variable Pages

| Page | Type | Title | Desc | Actions | Path |
|------|------|-------|:----:|---------|------|
| Index | Simple | Shared Variables | ✅ | — | `livewire/shared-variables/index.blade.php` |
| Team Vars | Simple | Team Shared Variables | ✅ | + Add, Dev view | `livewire/shared-variables/team/index.blade.php` |
| Projects | Simple | Projects | ✅ | — | `livewire/shared-variables/project/index.blade.php` |
| Project Vars | Simple | Shared Variables for {project} | ✅ | + Add, Dev view | `livewire/shared-variables/project/show.blade.php` |
| Env Vars | Simple | Shared Variables for {project}/{env} | ✅ | + Add, Dev view | `livewire/shared-variables/environment/show.blade.php` |

### Other Detail Pages

| Page | Type | Nav | Title | Desc | Actions | Path |
|------|------|-----|-------|:----:|---------|------|
| Destination Detail | Simple | — | Destination | ✅ | Save, Delete | `livewire/destination/show.blade.php` |
| Storage Detail | Simple | — | Storage Details | ✅ | Save, Delete | `livewire/storage/form.blade.php` |
| GitHub App | Simple | — | GitHub App | ✅ | Save, Delete | `livewire/source/github/change.blade.php` |

---

## 2. Form Sections Within Pages

### Legend

- **Card** = wrapped in `form-card` or `form-subsection` CSS class
- **Title** = heading uses `form-section-title` (right-aligned buttons)

### Type A: Main Form Sections (heading + save + fields in card)

| Page | Section | Card | Title | Desc | Spacing | Path |
|------|---------|:----:|:-----:|:----:|---------|------|
| Settings General | General | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/settings/index.blade.php` |
| Settings Advanced | Advanced | ✅ | ✅ | ✅ | mt-1, mt-4, gap-1 | `livewire/settings/advanced.blade.php` |
| Settings Updates | Updates | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/settings/updates.blade.php` |
| Settings Email | Transactional Email | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/settings-email.blade.php` |
| Settings Email | SMTP Server | ✅ | ✅ | ❌ | mt-4, gap-4 | `livewire/settings-email.blade.php` |
| Settings Email | Resend | ✅ | ✅ | ❌ | mt-4, gap-4 | `livewire/settings-email.blade.php` |
| Settings OAuth | Authentication | ✅ | ✅ | ✅ | mt-1, mt-4, gap-4 | `livewire/settings-oauth.blade.php` |
| Settings Backup | Backup | ❌ | ✅ | ✅ | mt-1, gap-2 | `livewire/settings-backup.blade.php` |
| Profile | General | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/profile/index.blade.php` |
| Profile | Change Password | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/profile/index.blade.php` |
| Profile | Two-factor Auth | ✅ | ✅ | ❌ | mt-4, gap-4 | `livewire/profile/index.blade.php` |
| Team General | General | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/team/index.blade.php` |
| Server General | General | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/server/show.blade.php` |
| Server Advanced | Advanced | ✅ | ✅ | ✅ | mt-4, gap-6 | `livewire/server/advanced.blade.php` |
| Server Swarm | Swarm | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/server/swarm.blade.php` |
| Server Docker Cleanup | Docker Cleanup | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/server/docker-cleanup.blade.php` |
| Server Proxy | Configuration | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/server/proxy.blade.php` |
| Server Terminal Access | Terminal Access | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/server/security/terminal-access.blade.php` |
| Server CA Cert | CA Certificate | ✅ | ✅ | ❌ | mt-4 | `livewire/server/ca-certificate/show.blade.php` |
| App General | General | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/project/application/general.blade.php` |
| App Advanced | Advanced | ✅ | ✅ | ✅ | mt-1, pt-4, gap-1 | `livewire/project/application/advanced.blade.php` |
| App Swarm | Swarm Configuration | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/project/application/swarm.blade.php` |
| App Source | Source | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/project/application/source.blade.php` |
| App Rollback | Rollback | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/project/application/rollback.blade.php` |
| App Preview Form | Preview Deployments | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/project/application/preview/form.blade.php` |
| Shared Health Checks | Healthchecks | ✅ | ✅ | ✅ | mt-1, mt-4, gap-4 | `livewire/project/shared/health-checks.blade.php` |
| Shared Metrics | Metrics | ✅ | ✅ | ✅ | mt-1, mt-4 | `livewire/project/shared/metrics.blade.php` |
| Shared Webhooks | Webhooks | ✅ | ✅ | ❌ | mt-4 | `livewire/project/shared/webhooks.blade.php` |
| Shared Env Vars | Environment Variables | ✅ | ✅ | ✅ | mt-1, mt-4, pt-2 | `livewire/project/shared/environment-variable/all.blade.php` |
| DB PostgreSQL | General | ✅ | ✅ | ✅ | mt-4, gap-2 | `livewire/project/database/postgresql/general.blade.php` |
| DB MySQL | General | ✅ | ✅ | ✅ | mt-4, gap-2 | `livewire/project/database/mysql/general.blade.php` |
| DB MariaDB | General | ✅ | ✅ | ✅ | mt-4, gap-2 | `livewire/project/database/mariadb/general.blade.php` |
| DB MongoDB | General | ✅ | ✅ | ✅ | mt-4, gap-2 | `livewire/project/database/mongodb/general.blade.php` |
| DB Redis | General | ✅ | ✅ | ✅ | mt-4, gap-2 | `livewire/project/database/redis/general.blade.php` |
| DB KeyDB | General | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/project/database/keydb/general.blade.php` |
| DB Dragonfly | General | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/project/database/dragonfly/general.blade.php` |
| DB Clickhouse | General | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/project/database/clickhouse/general.blade.php` |
| Service Stack | Service Stack | ✅ | ✅ | ✅ | mt-1, mt-4, gap-4 | `livewire/project/service/stack-form.blade.php` |
| Service Storage | Storages | ✅ | ✅ | ✅ | mt-1, gap-2 | `livewire/project/service/storage.blade.php` |
| Notif. Discord | Discord | ✅ | ✅ | ❌ | mt-4 | `livewire/notifications/discord.blade.php` |
| Notif. Slack | Slack | ✅ | ✅ | ❌ | mt-4 | `livewire/notifications/slack.blade.php` |
| Notif. Telegram | Telegram | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/notifications/telegram.blade.php` |
| Notif. Pushover | Pushover | ✅ | ✅ | ❌ | mt-4, gap-2 | `livewire/notifications/pushover.blade.php` |
| Notif. Webhook | Webhook | ✅ | ✅ | ❌ | mt-4 | `livewire/notifications/webhook.blade.php` |
| Storage | Storage Details | ✅ | ✅ | ✅ | mt-1, mt-4, gap-4 | `livewire/storage/form.blade.php` |
| GitHub App | GitHub App | ✅ | ✅ | ✅ | mt-1, mt-4, gap-2 | `livewire/source/github/change.blade.php` |
| Private Key | Private Key | ✅ | ✅ | ❌ | gap-10 | `livewire/security/private-key/show.blade.php` |
| Tags | Tags | ✅ | ✅ | ✅ | mt-1, pb-10 | `livewire/tags/show.blade.php` |

### Type B: Notification Settings Cards (checkbox groups in cards)

| Page | Section | Card | h3 class | Spacing | Path |
|------|---------|:----:|----------|---------|------|
| Discord | Deployments/Backups/Tasks/Server | ✅ | `font-medium mb-3` | gap-1.5, pl-1 | `livewire/notifications/discord.blade.php` |
| Slack | (same 4 sections) | ✅ | `font-medium mb-3` | gap-1.5, pl-1 | `livewire/notifications/slack.blade.php` |
| Telegram | (same 4 sections) | ✅ | **`text-lg font-medium mb-3`** | gap-1.5, pl-1 | `livewire/notifications/telegram.blade.php` |
| Pushover | (same 4 sections) | ✅ | `font-medium mb-3` | gap-1.5, pl-1 | `livewire/notifications/pushover.blade.php` |
| Webhook | (same 4 sections) | ✅ | `font-medium mb-3` | gap-1.5, pl-1 | `livewire/notifications/webhook.blade.php` |
| Email | (same 4 sections) | ✅ | `font-medium mb-3` | gap-1.5, pl-1 | `livewire/notifications/email.blade.php` |

### Type C: Sub-sections within forms (h3 headings, typically no card)

| Page | Sub-section | Card | h3 class | Spacing | Path |
|------|-------------|:----:|----------|---------|------|
| DB all (8) | Network | ❌ | `py-2` | gap-2 | `livewire/project/database/*/general.blade.php` |
| DB all (8) | SSL Configuration | ❌ | (none) | gap-2 | `livewire/project/database/*/general.blade.php` |
| DB all (8) | Proxy | ❌ | (none) | py-2 | `livewire/project/database/*/general.blade.php` |
| DB PostgreSQL | Advanced | ✅ | (none) | gap-4, pb-16 | `livewire/project/database/postgresql/general.blade.php` |
| DB MySQL/MariaDB/MongoDB/Redis | Advanced | ❌ | `pt-4` | pt-4 | `livewire/project/database/*/general.blade.php` |
| DB KeyDB/Dragonfly | Advanced | ❌ | (outside form) | — | `livewire/project/database/*/general.blade.php` |
| Server Advanced | Disk Usage / Builds | ❌ | (none) | pt-4 | `livewire/server/advanced.blade.php` |
| Server Docker Cleanup | Cleanup Config / Advanced | ❌ | (none) | mt-6 | `livewire/server/docker-cleanup.blade.php` |
| App General | Domains | ❌ | `pt-6` | pt-6 | `livewire/project/application/general.blade.php` |
| App General | Docker Registry | ❌ | `pt-8` | pt-8 | `livewire/project/application/general.blade.php` |
| App General | Build / Network / Commands | ❌ | `pt-8` | pt-8 | `livewire/project/application/general.blade.php` |
| App Advanced | General/Docker/Container/Network/Logs/Git | ❌ | (none) | pt-4 | `livewire/project/application/advanced.blade.php` |
| App Source | Deploy Key / Change Git Source | ❌ | `pt-4` | pt-4 | `livewire/project/application/source.blade.php` |
| Shared Webhooks | Manual Git Webhooks | ❌ | (none) | gap-2 | `livewire/project/shared/webhooks.blade.php` |
| Shared Env Vars | Production / Preview Env Vars | ❌ | (none) | (none) | `livewire/project/shared/environment-variable/all.blade.php` |
| Shared Tags | Assigned Tags / Existing Tags | ❌ | `pt-4` | pt-4 | `livewire/project/shared/tags.blade.php` |
| Settings Advanced | (multiple h4 sub-sections) | ❌ | `pt-4` | pt-4 | `livewire/settings/advanced.blade.php` |
| Server Log Drains | New Relic / Axiom / Custom FluentBit | ❌ | (none) | (none) | `livewire/server/log-drains.blade.php` |
| Server Cloudflare Tunnel | Automated / Manual | ❌ | (none) | (none) | `livewire/server/cloudflare-tunnel.blade.php` |
| Server General | Link to Hetzner Cloud | ❌ | (none) | pt-6 | `livewire/server/show.blade.php` |
| Server Proxy | Advanced / Proxy Title | ❌ | (none) | (none) | `livewire/server/proxy.blade.php` |
| Server Create | Add Server by IP Address | ❌ | (none) | (none) | `livewire/server/create.blade.php` |
| DB Import | Choose Restore Method / Backup File / Restore from S3 / File Info | ❌ | (none) | (none) | `livewire/project/database/import.blade.php` |
| DB Backup Edit | Settings / Backup Retention / Local / S3 | ❌ | (none) | (none) | `livewire/project/database/backup-edit.blade.php` |
| App Previews | Deployments | ❌ | (none) | (none) | `livewire/project/application/previews.blade.php` |

### Type D: Page-level title bars (heading + action, no card wrapper)

| Page | Heading | Title | Desc | Desc style | Spacing | Path |
|------|---------|:-----:|:----:|------------|---------|------|
| Team Navbar | Team | ✅ | ✅ | `<p>` -mt-4 mb-4 | mb-6 | `components/team/navbar.blade.php` |
| Team Members | Members | ✅ | ✅ | `<p>` -mt-4 mb-4 | mb-6 | `livewire/team/member/index.blade.php` |
| Team Admin | Admin View | ✅ | ✅ | `<p>` -mt-4 mb-4 | mb-6 | `livewire/team/admin-view.blade.php` |
| Server Resources | Resources | ✅ | ✅ | `<p>` | mb-6 | `livewire/server/resources.blade.php` |
| Server Charts | Metrics | ❌ | ✅ | `<p>` | mb-1, mb-4 | `livewire/server/charts.blade.php` |
| Server Dyn Configs | Dynamic Configurations | ✅ | ✅ | `<p>` | mb-6 | `livewire/server/proxy/dynamic-configurations.blade.php` |
| Server Patches | Server Patching | ✅ | ✅ | mt-1 | (none) | `livewire/server/security/patches.blade.php` |
| Server Hetzner Token | Hetzner Token | ✅ | ✅ | mt-1 | (none) | `livewire/server/cloud-provider-token/show.blade.php` |
| Private Keys | Private Keys | ✅ | ❌ | — | mb-6 | `livewire/security/private-key/index.blade.php` |
| Cloud Init Scripts | Cloud-Init Scripts | ✅ | ✅ | pb-4 | mb-6 | `livewire/security/cloud-init-scripts.blade.php` |
| API Tokens | API Tokens | ✅ | ✅ | -mt-4 mb-4 | mb-6 | `livewire/security/api-tokens.blade.php` |
| Cloud Provider Tokens | Cloud Provider Tokens | ✅ | ✅ | -mt-4 mb-4 | mb-6 | `livewire/security/cloud-provider-tokens.blade.php` |
| DB Backups | Scheduled Backups | ✅ | ❌ | — | mb-6 | `livewire/project/database/backup/index.blade.php` |
| DB Backup Execs | Executions | ✅ | ❌ | — | mb-6 | `livewire/project/database/backup-executions.blade.php` |
| Service DB Backups | Scheduled Backups | ✅ | ❌ | — | mb-6 | `livewire/project/service/database-backups.blade.php` |
| Scheduled Tasks | Scheduled Tasks | ✅ | ❌ | — | mb-6 | `livewire/project/shared/scheduled-task/all.blade.php` |
| Deployments | Deployments | ✅ | ❌ | — | (none) | `livewire/project/application/deployment/index.blade.php` |
| Previews | Pull Requests on Git | ✅ | ❌ | — | (none) | `livewire/project/application/previews.blade.php` |
| Terminal | Terminal | ✅ | ✅ | `<p>` -mt-4 mb-4 | mb-6 | `livewire/terminal/index.blade.php` |
| Project Edit | Project Name | ✅ | ✅ | mt-1 pb-10 | (none) | `livewire/project/edit.blade.php` |
| Destination | Destination | ✅ | ✅ | mt-1 | (none) | `livewire/destination/show.blade.php` |
| GitHub App (unconfigured) | GitHub App | ✅ | ❌ | — | pb-4 | `livewire/source/github/change.blade.php` |
| Notif. Email | Email | ✅ | ❌ | — | (none) | `livewire/notifications/email.blade.php` |

### Type E: Card sub-sections (content cards within pages, no heading in title bar)

| Page | Section | Card | Spacing | Path |
|------|---------|:----:|---------|------|
| Team Members | Members table | ✅ | gap-6 | `livewire/team/member/index.blade.php` |
| Team Members | Invite section | ✅ | gap-6 | `livewire/team/member/index.blade.php` |
| Team Admin | Search + Users | ✅ | (none) | `livewire/team/admin-view.blade.php` |
| API Tokens | New Token | ✅ | mt-4, gap-2 | `livewire/security/api-tokens.blade.php` |
| API Tokens | Issued Tokens | ✅ | mt-4, gap-2 | `livewire/security/api-tokens.blade.php` |
| Cloud Provider Tokens | New Token | ✅ | (none) | `livewire/security/cloud-provider-tokens.blade.php` |
| Cloud Provider Tokens | Saved Tokens | ✅ | mt-4, gap-2 | `livewire/security/cloud-provider-tokens.blade.php` |

### Type F: Table cards (data tables wrapped in `form-card max-w-none`)

| Page | Table | Card | Path |
|------|-------|:----:|------|
| GitHub App | Resources | ✅ | `livewire/source/github/change.blade.php` |
| Server Resources | Managed resources | ✅ | `livewire/server/resources.blade.php` |
| Server Resources | Unmanaged resources | ✅ | `livewire/server/resources.blade.php` |
| Team Members | Pending Invitations | ✅ | `livewire/team/invitations.blade.php` |
| Team Members | Members table | ✅ | `livewire/team/member/index.blade.php` |
| Clone | Destination Server | ✅ | `livewire/project/clone-me.blade.php` |
| Clone | Resources | ✅ | `livewire/project/clone-me.blade.php` |
| Server Patching | Packages | ✅ | `livewire/server/security/patches.blade.php` |
| Previews | Pull Requests | ✅ | `livewire/project/application/previews.blade.php` |

---

## 3. Inconsistencies

| Issue | Affected |
|-------|----------|
| **Missing card** on main form | `settings-backup`, `notification/email` (title only) |
| **Missing description** on main form | Discord, Slack, Telegram, Pushover, Webhook, KeyDB, Dragonfly, Clickhouse, App Swarm, CA Cert, Profile General, Profile 2FA |
| **Sub-section spacing varies** (pt-4 vs pt-6 vs pt-8) | App General uses pt-6/pt-8; all others use pt-4 |
| **Field gap standardized** | `form-card` uses `gap-4`, `form-subsection` uses `gap-10`, standalone field containers use `gap-8` |
| **Telegram h3 uses `text-lg`** vs others `font-medium` only | `notifications/telegram.blade.php` |
| **DB Advanced section inconsistent** — PostgreSQL has card, others don't | All DB generals except PostgreSQL |
| ~~**Danger Zone** has no card or form-section-title~~ | ~~`livewire/team/index.blade.php`~~ — **Fixed**: now uses `danger-zone` class |
| **Server Charts** heading not using form-section-title | `livewire/server/charts.blade.php` |
| **Description style varies** in Type D | Some `<p> -mt-4 mb-4`, some `mt-1`, some `pb-4` |
| ~~**Title+buttons not using `form-section-title`**~~ | ~~8 files~~ — **Fixed**: shared-variables/team/index, shared-variables/project/show, project/new/github-private-repository, project/database/backup-edit, project/new/docker-compose, project/new/docker-image, project/new/simple-dockerfile, server/log-drains |

---

## 4. Colored Cards — Empty States, Danger Zones & Warning Zones

Three CSS utility classes in `resources/css/utilities.css` provide lightweight color-coded cards:

- **`empty-state`** (blue) — "Nothing found" messages
- **`danger-zone`** (red) — Destructive action sections (delete buttons, irreversible operations)
- **`warning-zone`** (yellow) — Warnings and notices (read-only volumes, unvalidated servers)

For richer structured alerts with icons and titles, `<x-callout>` remains unchanged.

### Empty States (`empty-state`) — 52 occurrences across 38 files

| File | Message(s) | Notes |
|------|-----------|-------|
| `livewire/dashboard.blade.php` | No projects found. / No private keys found. / No servers found. | Replaced `font-bold dark:text-warning` |
| `livewire/admin/index.blade.php` | No users found with {search} | |
| `livewire/terminal/index.blade.php` | No servers with terminal access found. | |
| `livewire/server/index.blade.php` | No servers found. Without a server… | |
| `livewire/server/resources.blade.php` | No managed resources found. / No unmanaged resources found. | |
| `livewire/server/charts.blade.php` | Metrics are disabled for this server… | Contains link |
| `livewire/server/private-key/show.blade.php` | No private keys found. | |
| `livewire/server/cloud-provider-token/show.blade.php` | No Hetzner tokens found. | |
| `livewire/server/docker-cleanup-executions.blade.php` | No executions found. | Replaced `p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm` |
| `livewire/server/proxy/dynamic-configurations.blade.php` | No dynamic configurations found. | Has `wire:loading.remove` |
| `livewire/security/private-key/index.blade.php` | No private keys found. | |
| `livewire/security/cloud-init-scripts.blade.php` | No cloud-init scripts found… | Replaced `text-neutral-500` |
| `livewire/security/cloud-provider-tokens.blade.php` | No cloud provider tokens found. | |
| `livewire/security/api-tokens.blade.php` | No API tokens found. | |
| `livewire/tags/show.blade.php` | No tags yet defined yet… / No deployments running. | 2 locations |
| `livewire/tags/deployments.blade.php` | No deployments running. | |
| `livewire/storage/index.blade.php` | No storage found. | |
| `livewire/destination/index.blade.php` | No destinations found. / No servers found. | 2 locations |
| `source/all.blade.php` | No sources found. | |
| `livewire/team/admin-view.blade.php` | No users found other than the root. | |
| `livewire/shared-variables/environment/show.blade.php` | No environment variables found. | |
| `livewire/shared-variables/environment/index.blade.php` | No environments found. / No project found. | 2 locations |
| `livewire/shared-variables/team/index.blade.php` | No environment variables found. | |
| `livewire/shared-variables/project/index.blade.php` | No project found. | |
| `livewire/shared-variables/project/show.blade.php` | No environment variables found. | |
| `livewire/project/show.blade.php` | No environments found. | |
| `livewire/project/resource/index.blade.php` | No resource found with… / No resources found… | 2 locations, search + default |
| `livewire/project/new/select.blade.php` | No resources found. / No validated & reachable servers found. | 2 locations |
| `livewire/project/new/github-private-repository.blade.php` | No repositories found. | |
| `livewire/project/application/deployment/index.blade.php` | No deployments found. | |
| `livewire/project/application/rollback.blade.php` | No images found locally. | |
| `livewire/project/application/source.blade.php` | No other sources found. | |
| `livewire/project/database/create-scheduled-backup.blade.php` | No validated S3 Storages found. | Replaced `text-red-500` |
| `livewire/project/database/scheduled-backups.blade.php` | No scheduled backups configured. | |
| `livewire/project/database/backup-executions.blade.php` | No executions found. | Replaced `p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm` |
| `livewire/project/database/postgresql/general.blade.php` | No initialization scripts found. | |
| `livewire/project/service/storage.blade.php` | No storage found. | 2 locations |
| `livewire/project/shared/logs.blade.php` | The resource is not running. (×3) / No containers are running… (×4) / Server not functional (×3) / No functional server found… (×3) | 10 occurrences; uses `pt-4 empty-state` and `pt-2 empty-state` |
| `livewire/project/shared/execute-container-command.blade.php` | No containers running… / Server not functional… | 2 locations |
| `livewire/project/shared/environment-variable/all.blade.php` | No environment variables found. | |
| `livewire/project/shared/destination.blade.php` | No additional servers available to attach. | |
| `livewire/project/shared/scheduled-task/all.blade.php` | No scheduled tasks configured. | |
| `livewire/project/shared/scheduled-task/executions.blade.php` | No executions found. | Replaced `p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm` |

### Danger Zones (`danger-zone`) — 3 occurrences

| File | Section | Notes |
|------|---------|-------|
| `livewire/project/shared/danger.blade.php` | Shared danger zone (apps, DBs, services) | Wraps entire component |
| `livewire/server/delete.blade.php` | Server delete section | Inside `@if ($server->id !== 0)` |
| `livewire/team/index.blade.php` | Team delete section | Bottom of page |

### Warning Zones (`warning-zone`) — 6 occurrences

| File | Message | Notes |
|------|---------|-------|
| `livewire/project/shared/storages/all.blade.php` | Volume is not allowed to be edited… | Replaced `bg-warning/10 text-warning` |
| `livewire/project/shared/storages/show.blade.php` | Volume is not allowed to be edited… | Replaced `bg-warning/10 text-warning` |
| `livewire/project/service/file-storage.blade.php` | Volume is not allowed to be edited… | Replaced `bg-warning/10 text-warning` |
| `livewire/server/destinations.blade.php` | Server is not validated. Validate first. | |
| `livewire/server/log-drains.blade.php` | Server is not validated. Validate first. | |
| `livewire/server/proxy/show.blade.php` | Server is not validated. Validate first. | |
