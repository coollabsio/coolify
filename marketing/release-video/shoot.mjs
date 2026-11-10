// Takes screenshots of a running Coolify instance from a release spec.
// Usage: node marketing/release-video/shoot.mjs <spec.json> [shotName,shotName...]
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const specPath = path.resolve(process.argv[2]);
const only = process.argv[3]?.split(',');
const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
const out = path.join(path.dirname(specPath), 'build', 'shots');
fs.mkdirSync(out, { recursive: true });

const base = process.env.COOLIFY_URL ?? spec.baseUrl;
const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1600, height: 900 },
  deviceScaleFactor: 2, // 2x keeps UI text sharp after the slide zooms in
  ignoreHTTPSErrors: true,
  colorScheme: 'dark',
});
const page = await context.newPage();
page.setDefaultTimeout(8000);

await page.goto(base + '/login');
await page.fill('input[name=email]', process.env.COOLIFY_EMAIL ?? 'test@example.com');
await page.fill('input[name=password]', process.env.COOLIFY_PASSWORD ?? 'password');
await page.click('button[type=submit]');
await page.waitForLoadState('networkidle');

// Step types: {click: "visible text"}, {clickSelector: "css"}, {fill: ["css", "value"]},
// {scrollTo: "visible text"}, {wait: ms}
async function runStep(step) {
  if (step.click) {
    // Buttons first, then list options, then links, then any visible element with exactly this text.
    const candidates = [
      page.locator('button:visible', { hasText: step.click }),
      page.getByRole('option', { name: step.click }),
      page.locator('a:visible', { hasText: step.click }),
      page.locator(':visible', { hasText: new RegExp(`^${step.click}$`) }),
    ];
    for (const candidate of candidates) {
      if (await candidate.count()) {
        await candidate.first().click();
        break;
      }
    }
  } else if (step.clickSelector) {
    await page.locator(step.clickSelector).first().click();
  } else if (step.fill) {
    await page.fill(step.fill[0], step.fill[1]);
  } else if (step.scrollTo) {
    await page.getByText(step.scrollTo).first().scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -120));
  } else if (step.wait) {
    await page.waitForTimeout(step.wait);
  }
  await page.waitForTimeout(900);
}

let failed = 0;
for (const shot of spec.shots) {
  if (only && !only.includes(shot.name)) continue;
  try {
    const response = await page.goto(base + shot.url, { waitUntil: 'networkidle' }).catch(() => null);
    if (response && response.status() >= 400) throw new Error(`HTTP ${response.status()}, the page is broken`);
    await page.waitForTimeout(800);
    for (const step of shot.steps ?? []) {
      await runStep(step).catch((e) => { throw new Error(`step ${JSON.stringify(step)}: ${e.message}`); });
    }
    await page.screenshot({ path: path.join(out, shot.name + '.png') });
    console.log('ok  ', shot.name, page.url());
  } catch (e) {
    failed++;
    console.log('FAIL', shot.name, e.message.split('\n')[0]);
  }
}
await browser.close();
process.exit(failed ? 1 : 0);
