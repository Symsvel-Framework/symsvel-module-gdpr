import { describe, expect, it, vi } from 'vitest';
import { ConsentApi, ConsentStorage, parseState, permit, selection, type ConsentState } from './consent-client';
const credential = 'a'.repeat(64) + '.' + 'b'.repeat(64);
const current: ConsentState = {
  enabled: true, complete: true, version: '1'.repeat(32), expires_at: '2099-01-01T00:00:00Z',
  purposes: [{ subject: 'tracker-example', revision: 2, name: 'Example', purpose: 'Audience',
    description: 'Description', privacy_url: 'https://example.invalid/privacy', decided: true, granted: true }]
};
describe('consent client', () => {
  it('keeps the initial read anonymous and never sends cookies', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => new Response(JSON.stringify({ data: current })));
    const api = new ConsentApi('front', 'https://api.example.test', fetcher);
    await api.state(null);
    expect(fetcher.mock.calls[0][0]).toBe('https://api.example.test/apiopen/gdpr/front/consent/state');
    const init = fetcher.mock.calls[0][1]!;
    expect(init.credentials).toBe('omit');
    expect(init.redirect).toBe('error');
    expect(new Headers(init.headers).has('Authorization')).toBe(false);
    await api.state(credential);
    expect(new Headers(fetcher.mock.calls[1][1]?.headers).get('Authorization')).toBe('Bearer ' + credential);
  });
  it('sends explicit complete revisioned choices, including refusal', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => new Response(JSON.stringify({ data: { ...current, credential } })));
    const api = new ConsentApi('app', 'https://api.example.test', fetcher);
    await api.save(null, null, selection(current, () => false));
    expect(JSON.parse(String(fetcher.mock.calls[0][1]?.body))).toEqual({ version: null, choices: { 'tracker-example': { revision: 2, granted: false } } });
  });
  it('rejects malformed responses and refuses expired, incomplete or locally blocked grants', () => {
    expect(() => parseState({ ...current, purposes: [...current.purposes, ...current.purposes] })).toThrow();
    expect(() => parseState({ ...current, purposes: [{ ...current.purposes[0], privacy_url: 'javascript:alert(1)' }] })).toThrow();
    expect(permit(current, credential, false)).not.toBeNull();
    expect(permit(current, credential, true)).toBeNull();
    expect(permit(current, null, false)).toBeNull();
    expect(permit({ ...current, complete: false }, credential, false)).toBeNull();
    expect(permit(current, credential, false, Date.parse(current.expires_at!))).toBeNull();
    expect(permit({ ...current, purposes: [{ ...current.purposes[0], granted: false }] }, credential, false)).toBeNull();
  });
  function sharedStorage(values = new Map<string, string>()) {
    return { values, storage: {
      get length() { return values.size; },
      key: (index: number) => [...values.keys()][index] ?? null,
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => { values.set(key, value); },
      removeItem: (key: string) => { values.delete(key); }
    } };
  }
  it('writes no browser storage until explicit choice and keeps a local block on failed changes', () => {
    const { values, storage: raw } = sharedStorage();
    const storage = new ConsentStorage('front', raw);
    expect(storage.read()).toBeNull();
    expect(values.size).toBe(0);
    expect(storage.commit(credential, storage.begin(), false)).toBe(true);
    expect(storage.read()).toBe(credential);
    const failed = storage.begin();
    storage.fail(failed);
    expect(storage.blocked()).toBe(true);
    expect(permit(current, storage.read(), storage.blocked())).toBeNull();
    expect(storage.commit(credential, storage.begin(), false)).toBe(true);
    expect(storage.blocked()).toBe(false);
  });
  it('never lets an older response clear a later explicit block, with or without a lock', () => {
    for (const exclusive of [false, true]) {
      const { storage: raw } = sharedStorage();
      const first = new ConsentStorage('front', raw);
      const second = new ConsentStorage('front', raw);
      const older = first.begin();
      const newer = second.begin();
      expect(first.canWrite(older, exclusive)).toBe(false);
      expect(first.commit(credential, older, exclusive)).toBe(false);
      expect(second.blocked()).toBe(true);
      expect(first.read()).toBeNull();
      first.fail(older);
      second.fail(newer);
      const retry = second.begin();
      expect(second.commit(credential, retry, exclusive)).toBe(true);
      expect(first.blocked()).toBe(false);
      expect(first.commit('c'.repeat(64) + '.' + 'd'.repeat(64), older, exclusive)).toBe(false);
      expect(first.read()).toBe(credential);
    }
  });
  it('does not take over a pending write without native exclusive ownership', () => {
    const { storage: raw } = sharedStorage();
    const first = new ConsentStorage('front', raw);
    const second = new ConsentStorage('front', raw);
    first.begin();
    const later = second.begin();
    expect(second.canWrite(later, false)).toBe(false);
    expect(second.canWrite(later, true)).toBe(true);
    expect(second.affects(later.marker)).toBe(true);
    expect(second.affects('symsvel.gdpr.app.blocked.example')).toBe(false);
    expect(second.affects(null)).toBe(true);
  });
  it('supports legacy blockers and leaves a newly inserted blocker intact during commit', () => {
    const { values, storage: raw } = sharedStorage();
    values.set('symsvel.gdpr.front.blocked', '1');
    const storage = new ConsentStorage('front', {
      ...raw,
      get length() { return values.size; },
      setItem(key, value) {
        raw.setItem(key, value);
        if (key === 'symsvel.gdpr.front') raw.setItem(key + '.blocked.concurrent', 'pending');
      }
    });
    const attempt = storage.begin();
    expect(storage.commit(credential, attempt, false)).toBe(false);
    expect(storage.blocked()).toBe(true);
    expect(values.has('symsvel.gdpr.front.blocked.concurrent')).toBe(true);
    expect(values.has('symsvel.gdpr.front.blocked')).toBe(false);
  });
  it('rejects foreign insecure origins and unexpected credentials on state reads', async () => {
    expect(() => new ConsentApi('front', 'http://api.example.com')).toThrow();
    const api = new ConsentApi('front', 'https://api.example.test', async () => new Response(JSON.stringify({ data: { ...current, credential } })));
    await expect(api.state(null)).rejects.toThrow();
  });
});
