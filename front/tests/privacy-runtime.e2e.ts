import { expect, test } from '@playwright/test';

test('installed GDPR mounts exactly once and does not create identity before a choice', async ({ page }) => {
  let reads = 0;
  let writes = 0;
  await page.route('**/apiopen/project', route => route.fulfill({ json: { data: { name: 'GDPR CT' } } }));
  await page.route('**/apiopen/gdpr/front/consent/state', route => {
    reads++;
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['authorization']).toBeUndefined();
    return route.fulfill({ json: { data: { enabled: true, complete: false, version: null, expires_at: null,
      purposes: [{ subject: 'tracker-example', revision: 1, name: 'Example', purpose: 'Audience',
        description: 'Description', privacy_url: 'https://example.invalid/privacy', decided: false, granted: false }] } } });
  });
  await page.route('**/apiopen/gdpr/front/consent', route => {
    writes++;
    expect(route.request().method()).toBe('PUT');
    expect(route.request().postDataJSON().choices['tracker-example'].granted).toBe(false);
    return route.fulfill({ json: { data: { enabled: true, complete: true, version: '1'.repeat(32),
      expires_at: '2099-01-01T00:00:00Z', credential: 'a'.repeat(64) + '.' + 'b'.repeat(64),
      purposes: [{ subject: 'tracker-example', revision: 1, name: 'Example', purpose: 'Audience',
        description: 'Description', privacy_url: 'https://example.invalid/privacy', decided: true, granted: false }] } } });
  });
  await page.goto('http://localhost:4173/');
  const refuse = page.getByRole('button', { name: /Tout refuser|Refuse all/ });
  await expect(refuse).toBeVisible();
  await expect(refuse).toHaveCount(1);
  expect(reads).toBe(1);
  expect(writes).toBe(0);
  expect(await page.evaluate(() => localStorage.getItem('symsvel.gdpr.front'))).toBeNull();
  await refuse.click();
  await expect(page.getByRole('button', { name: /Préférences cookies|Cookie preferences/ })).toBeVisible();
  expect(writes).toBe(1);
  
});
