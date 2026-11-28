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

// Real tabs share the origin's storage and native lock. An overlapping refusal
// must fence a still-pending acceptance instead of being silently cleared.
test('overlapping choices preserve the newer local blocker until explicit retry', async ({ page, context }) => {
  const scope = 'front';
  const origin = 'http://localhost:4173';
  const credential = 'a'.repeat(64) + '.' + 'b'.repeat(64);
  let version: string | null = null;
  let granted = false;
  let writes = 0;
  let hold = false;
  let release!: () => void;
  const gate = new Promise<void>(resolve => { release = resolve; });
  const state = () => ({ enabled: true, complete: version !== null, version,
    expires_at: version === null ? null : '2099-01-01T00:00:00Z',
    purposes: [{ subject: 'tracker-example', revision: 1, name: 'Example', purpose: 'Audience',
      description: 'Description', privacy_url: 'https://example.invalid/privacy', decided: version !== null, granted }] });
  await context.route('**/apiopen/project', route => route.fulfill({ json: { data: { name: 'GDPR CT' } } }));
  await context.route('**/apiopen/gdpr/' + scope + '/consent/state', route => route.fulfill({ json: { data: state() } }));
  await context.route('**/apiopen/gdpr/' + scope + '/consent', async route => {
    const input = route.request().postDataJSON();
    const existing = route.request().headers()['authorization'] === 'Bearer ' + credential;
    writes++;
    if (hold) await gate;
    granted = input.choices['tracker-example'].granted;
    version = writes.toString(16).padStart(32, '0');
    return route.fulfill({ json: { data: { ...state(), ...(existing ? {} : { credential }) } } });
  });
  const open = async (tab: import('@playwright/test').Page) => {
    await tab.goto(origin + '/');
  };
  const preferences = (tab: import('@playwright/test').Page) => tab.getByRole('button', { name: /Préférences cookies|Cookie preferences/ });
  const accept = (tab: import('@playwright/test').Page) => tab.getByRole('button', { name: /Tout accepter|Accept all/ });
  const refuse = (tab: import('@playwright/test').Page) => tab.getByRole('button', { name: /Tout refuser|Refuse all/ });
  const blockers = (tab: import('@playwright/test').Page) => tab.evaluate(scope =>
    Object.keys(localStorage).filter(key => key.startsWith('symsvel.gdpr.' + scope + '.blocked')), scope);
  await open(page);
  await accept(page).click();
  await expect(preferences(page)).toBeVisible();
  const other = await context.newPage();
  await open(other);
  await expect(preferences(other)).toBeVisible();
  await preferences(page).click();
  hold = true;
  await accept(page).click();
  try {
    await expect.poll(() => writes).toBe(2);
    await expect(refuse(other)).toBeVisible();
    await refuse(other).click();
    await expect(other.getByRole('alert')).toBeVisible();
    expect(writes).toBe(2);
    expect((await blockers(other)).length).toBeGreaterThanOrEqual(2);
  } finally { hold = false; release(); }
  await expect(page.getByRole('alert')).toBeVisible();
  await expect.poll(async () => (await blockers(other)).length).toBeGreaterThan(0);
  await refuse(other).click();
  await expect.poll(() => writes).toBe(3);
  await expect(preferences(other)).toBeVisible();
  expect(await blockers(other)).toEqual([]);
  expect(granted).toBe(false);
});
