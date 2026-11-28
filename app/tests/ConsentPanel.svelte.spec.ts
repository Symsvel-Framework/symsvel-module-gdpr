import { page } from 'vitest/browser';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'vitest-browser-svelte';
import ConsentPanel from './ConsentPanel.svelte';
import { ConsentStorage, type ConsentState, type ConsentPermit } from './consent-client';

const credential = 'a'.repeat(64) + '.' + 'b'.repeat(64);
const version = '1'.repeat(32);
const initial: ConsentState = {
  enabled: true, complete: false, version: null, expires_at: null,
  purposes: [{ subject: 'tracker-example', revision: 2, name: 'Example', purpose: 'Audience',
    description: 'Description', privacy_url: 'https://example.invalid/privacy', decided: false, granted: false }]
};
function current(granted: boolean): ConsentState {
  return { ...initial, complete: true, version, expires_at: '2099-01-01T00:00:00Z',
    purposes: [{ ...initial.purposes[0], decided: true, granted }] };
}
function reply(data: ConsentState): Response {
  return new Response(JSON.stringify({ data }), { headers: { 'Content-Type': 'application/json' } });
}
describe('ConsentPanel', () => {
  beforeEach(() => { localStorage.clear(); });

  it('offers equal refusal and acceptance without creating browser identity before a choice', async () => {
    const fetcher = vi.fn<typeof fetch>(async (input, init) => {
      if (String(input).endsWith('/state')) return reply(initial);
      const body = JSON.parse(String(init?.body));
      expect(body.choices['tracker-example'].granted).toBe(false);
      expect(body.version).toBeNull();
      return reply({ ...current(false), credential });
    });
    render(ConsentPanel, { scope: 'front', apiOrigin: 'https://api.example.test', language: 'fr', fetcher });
    await expect.element(page.getByRole('button', { name: 'Tout accepter' })).toBeVisible();
    await expect.element(page.getByRole('button', { name: 'Tout refuser' })).toBeVisible();
    expect(localStorage.length).toBe(0);
    await page.getByRole('button', { name: 'Tout refuser' }).click();
    await expect.element(page.getByRole('button', { name: 'Préférences cookies' })).toBeVisible();
    expect(localStorage.getItem('symsvel.gdpr.front')).toBe(credential);
    expect(new ConsentStorage('front', localStorage).blocked()).toBe(false);
  });

  it('publishes a permit only after server confirmation and invalidates it before withdrawal', async () => {
    const onPermitChange = vi.fn<(value: ConsentPermit | null) => void>();
    let saved = false;
    const fetcher = vi.fn<typeof fetch>(async (input, init) => {
      if (String(input).endsWith('/state')) return reply(saved ? current(true) : initial);
      const granted = JSON.parse(String(init?.body)).choices['tracker-example'].granted;
      if (granted) {
        saved = true;
        return reply({ ...current(true), credential });
      }
      expect(onPermitChange.mock.calls.at(-1)?.[0]).toBeNull();
      return reply({ ...current(false), version: '2'.repeat(32) });
    });
    render(ConsentPanel, { scope: 'front', apiOrigin: 'https://api.example.test', language: 'fr', fetcher, onPermitChange });
    await expect.element(page.getByRole('button', { name: 'Tout accepter' })).toBeVisible();
    expect(onPermitChange.mock.calls.every(([value]) => value === null)).toBe(true);
    await page.getByRole('button', { name: 'Tout accepter' }).click();
    await expect.element(page.getByRole('button', { name: 'Préférences cookies' })).toBeVisible();
    expect(onPermitChange.mock.calls.at(-1)?.[0]).toEqual({ credential, version, expiresAt: Date.parse(current(true).expires_at!) });
    await page.getByRole('button', { name: 'Préférences cookies' }).click();
    await page.getByRole('button', { name: 'Tout refuser' }).click();
    await expect.element(page.getByRole('button', { name: 'Préférences cookies' })).toBeVisible();
    expect(onPermitChange.mock.calls.at(-1)?.[0]).toBeNull();
  });

  it('allows withdrawal from preferences and keeps the block if the server is unavailable', async () => {
    localStorage.setItem('symsvel.gdpr.front', credential);
    const fetcher = vi.fn<typeof fetch>(async (input, init) => {
      if (String(input).endsWith('/state')) return reply(current(true));
      expect(JSON.parse(String(init?.body)).choices['tracker-example'].granted).toBe(false);
      expect(new ConsentStorage('front', localStorage).blocked()).toBe(true);
      return new Response('{}', { status: 503 });
    });
    render(ConsentPanel, { scope: 'front', apiOrigin: 'https://api.example.test', language: 'fr', fetcher });
    await page.getByRole('button', { name: 'Préférences cookies' }).click();
    await expect.element(page.getByRole('checkbox')).toBeChecked();
    await page.getByRole('button', { name: 'Tout refuser' }).click();
    await expect.element(page.getByRole('alert')).toHaveTextContent('La modification');
    await expect.element(page.getByRole('checkbox')).not.toBeChecked();
    expect(new ConsentStorage('front', localStorage).blocked()).toBe(true);
  });
});
