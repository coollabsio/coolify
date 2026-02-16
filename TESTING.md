# Coolify – Deploy Local & Run All Tests

This doc covers deploying the local environment and running **Unit**, **Integration (Feature)**, **E2E (Dusk + Playwright)**, and **exploratory testing with Playwright MCP**.

## Prerequisites

- **Docker Desktop** (for app + Feature tests). If you see `failed to stat parent` when building, try:
  - Restart Docker Desktop, or
  - `docker system prune -f` and rebuild.
- **PHP 8.4+** and **Composer** (optional; for running Unit tests on the host).
- **Node 18+** (for Playwright E2E and `npm run test:e2e`).

## 1. Deploy local environment

```bash
# From repo root (use both compose files so postgres/redis have images)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Wait until the app is up, e.g.:

```bash
curl -s http://localhost:8000/api/health
```

App URL: **http://localhost:8000** (Vite dev: **http://localhost:5173**).

## 2. Unit tests (Pest)

- **Inside Docker (recommended):**
  ```bash
  docker exec coolify ./vendor/bin/pest --compact tests/Unit
  ```
- **On host** (no Docker, requires PHP + Composer):
  ```bash
  composer install
  ./vendor/bin/pest --compact tests/Unit
  # or: composer test:unit
  ```
- **All tests via Composer:** `composer test` (Pest), `composer test:unit`, `composer test:feature`

## 3. Feature / Integration tests (Pest)

Must run inside the Coolify container (database and app context):

```bash
docker exec coolify ./vendor/bin/pest --compact tests/Feature
```

## 4. E2E – Dusk (Laravel)

Requires ChromeDriver (e.g. port 4444) and app running at `http://localhost:8000`:

```bash
docker exec coolify php artisan dusk tests/Browser
```

Or on host: `php artisan dusk tests/Browser` (with `.env.dusk.*` if needed).

## 5. E2E – Playwright

Install and run:

```bash
npm install
npx playwright install
npm run test:e2e
```

- **UI mode (exploratory):** `npm run test:e2e:ui`
- **Base URL:** set `APP_URL` (default `http://localhost:8000`) if the app is elsewhere.

Tests live in `tests/e2e/` (e.g. `exploratory.spec.ts`, `smoke.spec.ts`).

## 6. Exploratory testing with Playwright MCP

With a **Playwright MCP** server (e.g. in Cursor):

1. Start the app: `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d` (or `spin up`).
2. Open **http://localhost:8000** in the browser you control via MCP.
3. Use MCP tools to drive the browser (click, fill, navigate) and explore login, dashboard, servers, projects, etc.

Playwright specs in `tests/e2e/` can be extended or used as a reference for MCP-driven flows.

## One-shot: deploy + all tests

**PowerShell:**

```powershell
.\scripts\deploy-and-test.ps1
# Skip deploy (app already up):
.\scripts\deploy-and-test.ps1 -SkipDeploy
# Unit only (e.g. when Docker is broken):
.\scripts\deploy-and-test.ps1 -SkipDeploy -UnitOnly
```

**Bash:**

```bash
./scripts/deploy-and-test.sh
# Skip deploy:
SKIP_DEPLOY=1 ./scripts/deploy-and-test.sh
```

## Summary

| Test type        | Command / where to run                          |
|------------------|--------------------------------------------------|
| Unit             | `docker exec coolify ./vendor/bin/pest --compact tests/Unit` or `./vendor/bin/pest --compact tests/Unit` on host |
| Feature          | `docker exec coolify ./vendor/bin/pest --compact tests/Feature` |
| Dusk (E2E)       | `docker exec coolify php artisan dusk tests/Browser` |
| Playwright E2E   | `npm run test:e2e` (app at `APP_URL`)           |
| Exploratory (MCP)| Start app, open in browser, use Playwright MCP  |
