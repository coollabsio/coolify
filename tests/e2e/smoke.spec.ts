import { test, expect } from '@playwright/test';

/**
 * Smoke tests: critical paths after deploy.
 * Run after starting the app: npx playwright test tests/e2e/smoke.spec.ts
 */

test.describe('Smoke', () => {
  test('app responds on base URL', async ({ request }) => {
    const res = await request.get('/', { timeout: 45_000 });
    expect(res.status()).toBeLessThan(500);
  });

  test('login page returns 200', async ({ request }) => {
    const res = await request.get('/login', { timeout: 45_000 });
    expect(res.status()).toBe(200);
  });

  test('api health returns 200 when available', async ({ request }) => {
    const res = await request.get('/api/health');
    if (res.status() === 200) {
      const body = await res.text();
      expect(body.length).toBeGreaterThan(0);
    }
  });
});
