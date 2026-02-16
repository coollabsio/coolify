#!/usr/bin/env bash
# Deploy local Coolify dev environment and run all tests.
# Prereqs: Docker, PHP 8.4+ (for Unit on host if desired), Node (for Playwright).
# Usage: ./scripts/deploy-and-test.sh   or   SKIP_DEPLOY=1 ./scripts/deploy-and-test.sh

set -e
cd "$(dirname "$0")/.."

if [ "${SKIP_DEPLOY}" != "1" ]; then
  echo ""
  echo "==> Deploying dev environment (Docker Compose)..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
  echo "Waiting for Coolify to be healthy..."
  for i in $(seq 1 60); do
    if curl -sf http://localhost:8000/api/health >/dev/null 2>&1; then break; fi
    sleep 2
  done
fi

echo ""
echo "==> Unit tests (Pest)..."
docker exec coolify ./vendor/bin/pest --compact tests/Unit

echo ""
echo "==> Feature tests (Pest, inside Docker)..."
docker exec coolify ./vendor/bin/pest --compact tests/Feature

echo ""
echo "==> Dusk browser tests..."
docker exec coolify php artisan dusk tests/Browser || true

echo ""
echo "==> Playwright E2E tests..."
npm run test:e2e || true

echo ""
echo "Done. For exploratory testing with Playwright MCP, use app at http://localhost:8000"
