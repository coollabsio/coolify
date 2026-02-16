# Deploy local Coolify dev environment and run all tests.
# Prereqs: Docker Desktop, PHP 8.4+ (for Unit tests), Node (for Playwright).
# Usage: .\scripts\deploy-and-test.ps1   or   .\scripts\deploy-and-test.ps1 -SkipDeploy

param(
    [switch]$SkipDeploy,
    [switch]$UnitOnly,
    [switch]$NoPlaywright
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

# ---- 1. Deploy ----
if (-not $SkipDeploy) {
    Write-Host "`n==> Deploying dev environment (Docker Compose)..." -ForegroundColor Cyan
    docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Docker Compose failed. If you see 'failed to stat parent', try: Docker Desktop -> Restart, or: docker system prune -f" -ForegroundColor Yellow
        exit $LASTEXITCODE
    }
    Write-Host "Waiting for Coolify to be healthy..."
    $max = 60
    while ($max -gt 0) {
        try {
            $r = Invoke-WebRequest -Uri "http://localhost:8000/api/health" -UseBasicParsing -TimeoutSec 2 -ErrorAction SilentlyContinue
            if ($r.StatusCode -eq 200) { break }
        } catch {}
        Start-Sleep -Seconds 2
        $max--
    }
    if ($max -le 0) {
        Write-Host "Coolify did not become healthy in time." -ForegroundColor Yellow
    }
}

if ($UnitOnly) {
    # ---- 2a. Unit tests (no Docker required; run on host if PHP available)
    Write-Host "`n==> Unit tests (Pest)..." -ForegroundColor Cyan
    & ./vendor/bin/pest --compact tests/Unit
    exit $LASTEXITCODE
}

# ---- 2. Unit tests ----
Write-Host "`n==> Unit tests (Pest)..." -ForegroundColor Cyan
docker exec coolify ./vendor/bin/pest --compact tests/Unit 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Trying Unit tests on host (./vendor/bin/pest tests/Unit)..." -ForegroundColor Yellow
    & ./vendor/bin/pest --compact tests/Unit
}

# ---- 3. Feature / Integration tests (must run inside Docker) ----
Write-Host "`n==> Feature tests (Pest, inside Docker)..." -ForegroundColor Cyan
docker exec coolify ./vendor/bin/pest --compact tests/Feature
if ($LASTEXITCODE -ne 0) {
    Write-Host "Feature tests failed (exit $LASTEXITCODE)." -ForegroundColor Red
}

# ---- 4. Dusk (E2E) ----
Write-Host "`n==> Dusk browser tests..." -ForegroundColor Cyan
docker exec coolify php artisan dusk tests/Browser 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Dusk skipped or failed (ChromeDriver may be needed)." -ForegroundColor Yellow
}

# ---- 5. Playwright (E2E + exploratory) ----
if (-not $NoPlaywright) {
    Write-Host "`n==> Playwright E2E tests..." -ForegroundColor Cyan
    npm run test:e2e 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Run: npm install && npx playwright install && npm run test:e2e" -ForegroundColor Yellow
    }
}

Write-Host "`nDone. For exploratory testing with Playwright MCP, open the app at http://localhost:8000 and use your Playwright MCP tools." -ForegroundColor Green
