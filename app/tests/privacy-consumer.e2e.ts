import { expect, test } from '@playwright/test';

test('optional consumer retains the actual initial route after hydration', async ({ page }) => {
  await page.route('**/apiopen/project', route => route.fulfill({ json: { data: { name: 'GDPR CT' } } }));
  await page.route('**/apiopen/gdpr/app/consent/state', route => route.fulfill({ json: {
    data: { enabled: true, complete: false, version: null, expires_at: null,
      purposes: [{ subject: 'tracker-example', revision: 1, name: 'Fixture', purpose: 'Audience',
        description: 'Fixture', privacy_url: 'https://example.invalid/privacy', decided: false, granted: false }] }
  } }));
  await page.route('**/apiopen/gdpr/app/consent', route => route.fulfill({ json: {
    data: { enabled: true, complete: true, version: '1'.repeat(32), expires_at: '2099-01-01T00:00:00Z',
      credential: 'a'.repeat(64) + '.' + 'b'.repeat(64),
      purposes: [{ subject: 'tracker-example', revision: 1, name: 'Fixture', purpose: 'Audience',
        description: 'Fixture', privacy_url: 'https://example.invalid/privacy', decided: true, granted: false }] }
  } }));
  await page.goto('http://localhost:4173/login');
  await expect(page.getByRole('button', { name: /Tout refuser|Refuse all/ })).toBeVisible();
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-gdpr-fixture-route', '/login');
  await expect(html).toHaveAttribute('data-gdpr-fixture-path', '/login');
  await page.getByRole('button', { name: /Tout refuser|Refuse all/ }).click();
  await expect(page.getByRole('button', { name: /Préférences cookies|Cookie preferences/ })).toBeVisible();
  await page.locator('a[href$="/forgot-password"]').click();
  await expect(page).toHaveURL(/\/forgot-password$/);
  await expect(html).toHaveAttribute('data-gdpr-fixture-route', '/forgot-password');
  await expect(html).toHaveAttribute('data-gdpr-fixture-path', '/forgot-password');
});
