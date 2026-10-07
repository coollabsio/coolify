# Pre-release review: v4.4.1..HEAD

- Release base: tag `v4.4.1` (2026-10-07, newest tag, ancestor of HEAD; config version is 4.4.2 = unreleased next patch).
- Target: HEAD `b3e0e6f77` (branch multi-endpoint-s3-backups).
- Range: 71 commits (33 non-merge), 265 files, +13567/-2666.
- Dev env: multi-endpoint-s3-backups instance, https://devserver.tail661ee3.ts.net:20070

## Phase 2 — review areas
- [x] A1 Multi-S3 backups: data, migrations, jobs, retention
- [x] A2 Multi-S3 backups: API, UI, authorization, transfer, MCP
- [x] A3 Proxy: error pages, maintenance mode, basic auth hashing
- [x] A4 Hostinger + cloud provider tokens + server pages
- [x] A5 Deployments: compose, railpack, add-host, GitHub runners, cloudflared
- [x] A6 Search, listbox refactor, analytics IP mode, traffic, favicons
- [x] A7 DB flush, composer major upgrades, OpenAPI, versions/CI
- [x] A8 Full diff

## Findings log (status: open / explained / checked / fixed + tested / skipped by decision)
| # | Short | Status |
|---|---|---|
| H1 | Hostinger view uses deleted x-forms.datalist -> wizard + onboarding crash | open |
| H2 | helper 1.0.18 not published (only 1.0.18-next) -> deploys/upgrade fail | open |
| H3 | TRAFFIC_IP_MODE ignored by pinned Sentinel 1.0.2 (privacy) | open |
| M1 | busybox error-page container failure aborts proxy setup, force_stop stays | open |
| M2 | backfill skips executions with s3_uploaded NULL -> old S3 files never deleted | open |
| M3 | S3Storage delete: ~3 queries per replica, no transaction | open |
| M4 | basic auth max:72 blocks saving General form for existing long passwords | open |
| M5 | API delete_from_provider rejects Hostinger servers | open |
| M6 | GitHub runners (privileged dind) allowed on deployment servers; warning lacks security risk | open |
| L1 | storage deleted mid-backup: FK error skips other destinations, SQL in message | open |
| L2 | job timeout not scaled with destination count; failed() nulls filename | open |
| L3 | DB S3 retention: no all-zero early return; large whereIn | open |
| L4 | legacy executions with deleted storage keep "live" replica | open |
| L5 | schedule delete: one S3 client/request per execution | open |
| L6 | DB API accepts unusable S3 storages | open |
| L7 | service execution list shows schedule storages, not replicas; warning links primary | open |
| L8 | Caddy: basic auth applies before maintenance page (Traefik differs) | open |
| L9 | Caddy maintenance relies on matcher merge; custom site address risk; wildcard skipped | open |
| L10 | clone copies maintenance state | open |
| L11 | no Power On button for stopped Hostinger VPS | open |
| L12 | stale Hostinger refresh test / missing aria-label | open |
| L13 | Hostinger purchase failure may adopt wrong VM or orphan one | open |
| L14 | read token can read Hostinger post-install script bodies | open |
| L15 | ByHostinger uses currentTeam() after mount; failed provider cancel leaves soft-deleted server | open |
| L16 | cloudflared update failure: tunnel down, reported success | open |
| L17 | validateComposeFile still sends base64 in argv (large files) | open |
| L18 | palette shows instance settings links to root-team non-admins | open |
| L19 | traffic path link without leading / can point to other host | open |
| L20 | symfony/yaml 8 throws on duplicate keys | open |
| L21 | openapi 403 response ref undefined | open |
| L22 | pint statement_indentation in HetznerServerCreationTest | open |
