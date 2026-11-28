import { expect, test } from '@playwright/test';

test('optional consumer retains the actual initial route after hydration', async ({ page }) => {
  await page.route('**/apiopen/project', route => route.fulfill({ json: { data: { name: 'GDPR CT' } } }));
  await page.route('**/apiopen/gdpr/front/consent/state', route => route.fulfill({ json: {
    data: { enabled: true, complete: false, version: null, expires_at: null,
      purposes: [{ subject: 'tracker-example', revision: 1, name: 'Fixture', purpose: 'Audience',
        description: 'Fixture', privacy_url: 'https://example.invalid/privacy', decided: false, granted: false }] }
  } }));
  await page.goto('http://localhost:4173/');
  await expect(page.getByRole('button', { name: /Tout refuser|Refuse all/ })).toBeVisible();
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-gdpr-fixture-route', '/');
  await expect(html).toHaveAttribute('data-gdpr-fixture-path', '/');

});
