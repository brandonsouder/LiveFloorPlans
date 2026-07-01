const assert = require('assert');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });

  await page.route('http://localhost:8097/**', route => {
    const url = route.request().url().replace('http://localhost:8097', 'http://host.docker.internal:8097');
    route.continue({ url });
  });

  await page.goto(process.env.BASE_URL || 'http://localhost:8097/floor-plan-test/', { waitUntil: 'networkidle' });

  await page.waitForSelector('.slfp-floor-plan');
  await page.waitForSelector('.slfp-label');

  const d1 = page.locator('.slfp-label', { hasText: 'D1' });
  assert.ok(await d1.count(), 'D1 label should be visible');
  assert.match(await d1.first().innerText(), /80 sq ft/);
  assert.match(await d1.first().innerText(), /\$425\/mo/);

  const d2 = page.locator('.slfp-label', { hasText: 'D2' });
  assert.match(await d2.first().innerText(), /Call for price/);

  const d4 = page.locator('.slfp-label.is-unavailable', { hasText: 'D4' });
  assert.ok(await d4.count(), 'D4 should be visible as unavailable');
  assert.doesNotMatch(await d4.first().innerText(), /\$625\/mo/);

  await d1.first().click();
  await page.waitForSelector('.slfp-detail:not([hidden])');
  assert.match(await page.locator('.slfp-detail').innerText(), /Suite D1/);
  assert.ok(await page.getByRole('link', { name: 'View Suite Page' }).count(), 'View Suite Page action should exist');

  await page.getByPlaceholder('Search suite').fill('D7');
  assert.ok(await page.locator('.slfp-label', { hasText: 'D7' }).count(), 'D7 should remain after search');
  assert.strictEqual(await page.locator('.slfp-label', { hasText: 'D1' }).count(), 0, 'D1 should hide after D7 search');

  await page.getByPlaceholder('Search suite').fill('');
  await page.getByLabel('Available only').check();
  assert.strictEqual(await page.locator('.slfp-label', { hasText: 'D4' }).count(), 0, 'Unavailable D4 should hide when available-only is checked');
  assert.ok(await page.locator('.slfp-label', { hasText: 'D1' }).count(), 'Available D1 should remain when available-only is checked');

  await page.screenshot({ path: '/tests/mobile-floorplan.png', fullPage: true });
  await browser.close();
  console.log('mobile floor plan checks passed');
})().catch(async error => {
  console.error(error);
  process.exit(1);
});
