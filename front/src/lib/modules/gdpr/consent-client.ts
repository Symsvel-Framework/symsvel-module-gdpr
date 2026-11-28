export type Scope = 'app' | 'front';
export type Choice = { revision: number; granted: boolean };
export type Purpose = Choice & {
  subject: string; name: string; purpose: string; description: string; privacy_url: string; decided: boolean;
};
export type ConsentState = {
  enabled: boolean; complete: boolean; version: string | null; expires_at: string | null;
  purposes: Purpose[]; credential?: string;
};
export type ConsentPermit = { credential: string; version: string; expiresAt: number };

const credentialPattern = /^[a-f0-9]{64}\.[a-f0-9]{64}$/;
const versionPattern = /^[a-f0-9]{32}$/;
const subjectPattern = /^[a-z][a-z0-9_-]{0,149}$/;
function object(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
export function isCredential(value: unknown): value is string {
  return typeof value === 'string' && credentialPattern.test(value);
}
function privacyUrl(value: unknown): value is string {
  if (typeof value !== 'string') return false;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && !url.username && !url.password;
  } catch { return false; }
}
function purpose(value: unknown): value is Purpose {
  return object(value) && typeof value.subject === 'string' && subjectPattern.test(value.subject)
    && typeof value.revision === 'number' && Number.isInteger(value.revision)
    && value.revision > 0 && value.revision <= 2147483647
    && typeof value.granted === 'boolean' && typeof value.decided === 'boolean'
    && (!value.granted || value.decided)
    && typeof value.name === 'string' && typeof value.purpose === 'string'
    && typeof value.description === 'string' && privacyUrl(value.privacy_url);
}
export function parseState(value: unknown): ConsentState {
  if (!object(value) || typeof value.enabled !== 'boolean' || typeof value.complete !== 'boolean'
    || !(value.version === null || (typeof value.version === 'string' && versionPattern.test(value.version)))
    || !(value.expires_at === null || (typeof value.expires_at === 'string' && Number.isFinite(Date.parse(value.expires_at))))
    || !Array.isArray(value.purposes) || value.purposes.length > 250 || !value.purposes.every(purpose)
    || new Set(value.purposes.map(p => p.subject)).size !== value.purposes.length
    || (value.complete && (!value.enabled || !value.version || !value.expires_at || value.purposes.some(p => !p.decided)))
    || (!value.enabled && (value.version !== null || value.expires_at !== null || value.purposes.length > 0))
    || (value.credential !== undefined && !isCredential(value.credential))) {
    throw new Error('Invalid consent response.');
  }
  return value as ConsentState;
}
export class ConsentHttpError extends Error {
  constructor(public readonly status: number) { super('Consent request failed.'); }
}
export class ConsentApi {
  private readonly base: string;
  constructor(private readonly scope: Scope, base: string, private readonly fetcher: typeof fetch = fetch) {
    const url = new URL(base);
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
      || ['.localhost', '.local', '.test'].some(suffix => url.hostname.endsWith(suffix));
    if (!['app', 'front'].includes(scope) || (url.protocol !== 'https:' && !(local && url.protocol === 'http:'))
      || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
      throw new Error('Invalid consent API origin.');
    }
    this.base = url.origin;
  }
  async state(credential: string | null): Promise<ConsentState> {
    const state = await this.request('/state', 'POST', credential, {});
    if (state.credential !== undefined) throw new Error('Unexpected consent credential.');
    return state;
  }
  async save(credential: string | null, version: string | null, choices: Record<string, Choice>): Promise<ConsentState> {
    const state = await this.request('', 'PUT', credential, { version, choices });
    if ((credential === null) !== (state.credential !== undefined)) throw new Error('Invalid consent credential response.');
    return state;
  }
  private async request(suffix: string, method: string, credential: string | null, body: object): Promise<ConsentState> {
    if (credential !== null && !isCredential(credential)) throw new Error('Invalid consent credential.');
    const headers = new Headers({ Accept: 'application/json', 'Content-Type': 'application/json' });
    if (credential) headers.set('Authorization', 'Bearer ' + credential);
    const response = await this.fetcher(this.base + '/apiopen/gdpr/' + this.scope + '/consent' + suffix, {
      method, headers, body: JSON.stringify(body), credentials: 'omit', cache: 'no-store',
      redirect: 'error', referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(10000)
    });
    if (!response.ok) throw new ConsentHttpError(response.status);
    const payload: unknown = await response.json();
    if (!object(payload)) throw new Error('Invalid consent response.');
    return parseState(payload.data);
  }
}
export function selection(state: ConsentState, granted: (purpose: Purpose) => boolean): Record<string, Choice> {
  return Object.fromEntries(state.purposes.map(p => [p.subject, { revision: p.revision, granted: granted(p) }]));
}

/** Browser storage is optional and used only for the dedicated consent credential. */
export type ConsentWrite = { marker: string; previous: readonly string[] };

export class ConsentStorage {
  readonly key: string;
  private readonly blockKey: string;
  constructor(scope: Scope, private readonly storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem' | 'length' | 'key'>) {
    this.key = 'symsvel.gdpr.' + scope;
    this.blockKey = this.key + '.blocked';
  }
  read(): string | null {
    const value = this.storage.getItem(this.key);
    return isCredential(value) ? value : null;
  }
  private isBlockKey(key: string): boolean {
    return key === this.blockKey || key.startsWith(this.blockKey + '.');
  }
  affects(key: string | null): boolean {
    return key === null || key === this.key || this.isBlockKey(key);
  }
  private blocks(): string[] {
    const keys: string[] = [];
    for (let i = 0; i < this.storage.length; i++) {
      const key = this.storage.key(i);
      if (key !== null && this.isBlockKey(key)) keys.push(key);
    }
    return keys;
  }
  blocked(): boolean { return this.blocks().length > 0; }
  begin(): ConsentWrite {
    // Each explicit intent owns a different durable blocker, even without Web Locks.
    const previous = this.blocks();
    const nonce = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    const marker = this.blockKey + '.' + nonce;
    this.storage.setItem(marker, 'pending');
    return { marker, previous };
  }
  canWrite(attempt: ConsentWrite, exclusive: boolean): boolean {
    const expected = new Set([...attempt.previous, attempt.marker]);
    const actual = this.blocks();
    return this.storage.getItem(attempt.marker) === 'pending'
      && actual.length === expected.size && actual.every(key => expected.has(key))
      // An exclusive browser lock proves that older pending attempts are abandoned.
      // In non-secure local development, never take ownership of another pending write.
      && (exclusive || attempt.previous.every(key => this.storage.getItem(key) !== 'pending'));
  }
  fail(attempt: ConsentWrite): void {
    if (this.storage.getItem(attempt.marker) === 'pending') this.storage.setItem(attempt.marker, 'failed');
  }
  clearCredential(): void { this.storage.removeItem(this.key); }
  commit(credential: string, attempt: ConsentWrite, exclusive: boolean): boolean {
    if (!isCredential(credential)) throw new Error('Invalid consent credential.');
    if (!this.canWrite(attempt, exclusive)) return false;
    this.storage.setItem(this.key, credential);
    // Never remove a blocker created after this explicit choice, even during commit.
    for (const key of attempt.previous) this.storage.removeItem(key);
    this.storage.removeItem(attempt.marker);
    return !this.blocked();
  }
}
export function permit(state: ConsentState | null, credential: string | null, blocked: boolean, now = Date.now()): ConsentPermit | null {
  if (blocked || !state?.enabled || !state.complete || !state.version || !state.expires_at
    || !isCredential(credential) || !state.purposes.some(p => p.granted && p.decided)) return null;
  const expiresAt = Date.parse(state.expires_at);
  return Number.isFinite(expiresAt) && expiresAt > now ? { credential, version: state.version, expiresAt } : null;
}
