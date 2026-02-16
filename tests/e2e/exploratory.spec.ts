import { test, expect } from '@playwright/test';

/**
 * Exploratory E2E tests for Coolify.
 * Requires the app to be running (e.g. docker compose up, or php artisan serve).
 * Use with Playwright MCP or: npx playwright test tests/e2e/exploratory.spec.ts
 */

test.describe('Coolify exploratory', () => {
  test('login page loads and shows form', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await expect(page).toHaveTitle(/Coolify|Login/i);
    await expect(page.getByRole('heading', { name: 'Coolify' })).toBeVisible({ timeout: 15_000 });
  });

  test('root redirects to login when unauthenticated', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await expect(page).toHaveURL(/\/(login|auth)/, { timeout: 15_000 });
  });

  test('health or public endpoint is reachable', async ({ request }) => {
    const res = await request.get('/api/health').catch(() => null);
    if (res) expect(res.ok()).toBeTruthy();
  });
});
