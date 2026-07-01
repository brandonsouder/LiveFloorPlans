const { test, expect } = require('@playwright/test');

test('mobile floor plan labels and bottom sheet work', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('http://wordpress/floor-plan-test/', { waitUntil: 'networkidle' });

  await expect(page.locator('.slfp-floor-plan')).toBeVisible();
  await expect(page.locator('.slfp-label').first()).toBeVisible();
  await expect(page.locator('.slfp-label', { hasText: 'D1' })).toContainText('80 sq ft');
  await expect(page.locator('.slfp-label', { hasText: 'D1' })).toContainText('$425/mo');
  await expect(page.locator('.slfp-label', { hasText: 'D2' })).toContainText('Call for price');
  await expect(page.locator('.slfp-label.is-unavailable', { hasText: 'D4' })).toBeVisible();
  await expect(page.locator('.slfp-label.is-unavailable', { hasText: 'D4' })).not.toContainText('$625/mo');

  await page.locator('.slfp-label', { hasText: 'D1' }).click();
  await expect(page.locator('.slfp-detail')).toBeVisible();
  await expect(page.locator('.slfp-detail')).toContainText('Suite D1');
  await expect(page.getByRole('link', { name: 'View Suite Page' })).toBeVisible();

  await page.getByPlaceholder('Search suite').fill('D7');
  await expect(page.locator('.slfp-label', { hasText: 'D7' })).toBeVisible();
  await expect(page.locator('.slfp-label', { hasText: 'D1' })).toHaveCount(0);

  await page.getByPlaceholder('Search suite').fill('');
  await page.getByLabel('Available only').check();
  await expect(page.locator('.slfp-label', { hasText: 'D4' })).toHaveCount(0);
  await expect(page.locator('.slfp-label', { hasText: 'D1' })).toBeVisible();
});
