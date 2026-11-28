<script lang="ts">
  import { onMount } from 'svelte';
  import { generatedTranslation } from '../../i18n/generated-translations';
  import fr from '../../translations/gdpr/fr';
  import en from '../../translations/gdpr/en';
  import { ConsentApi, ConsentHttpError, ConsentStorage, selection, permit, type Scope, type ConsentState, type ConsentPermit } from './consent-client';

  let { scope, apiOrigin = import.meta.env.PUBLIC_API_URL ?? '', language = 'fr', fetcher = fetch, onPermitChange }: {
    scope: Scope; apiOrigin?: string; language?: string; fetcher?: typeof fetch; onPermitChange?: (value: ConsentPermit | null) => void;
  } = $props();

  let consent = $state<ConsentState | null>(null);
  let draft = $state<Record<string, boolean>>({});
  let busy = $state(false);
  let expanded = $state(false);
  let blocked = $state(false);
  let expired = $state(false);
  let failure = $state<'unavailable' | 'conflict' | 'blocked' | null>(null);
  let api: ConsentApi | null = null;
  let store: ConsentStorage | null = null;
  let mounted = false;
  let generation = 0;
  let changedDuringSave = false;
  let published: ConsentPermit | null | undefined;
  let expiryTimer: number | undefined;
  let refreshTimer: number | undefined;

  function label(key: keyof typeof fr): string {
    return generatedTranslation(language, scope + '.gdpr.' + key)
      ?? (language.toLowerCase().startsWith('en') ? en[key] : fr[key]);
  }

  const offered = $derived(consent?.enabled === true && consent.purposes.length > 0);
  const required = $derived(offered && (!consent?.complete || blocked || expired));
  const show = $derived(offered && (required || expanded));

  function publish(value: ConsentPermit | null): void {
    if (expiryTimer !== undefined) window.clearTimeout(expiryTimer);
    expiryTimer = undefined;
    const unchanged = published !== undefined && (published === null && value === null
      || published !== null && value !== null && published.credential === value.credential
      && published.version === value.version && published.expiresAt === value.expiresAt);
    published = value;
    if (!unchanged) onPermitChange?.(value);
    if (value !== null) {
      expiryTimer = window.setTimeout(() => {
        if (value.expiresAt <= Date.now()) {
          expired = true;
          publish(null);
          if (!busy) void refresh();
        } else {
          publish(value);
        }
      }, Math.max(0, Math.min(value.expiresAt - Date.now(), 2147483647)));
    }
  }

  function apply(value: ConsentState): void {
    consent = value;
    blocked = store?.blocked() ?? true;
    expired = value.expires_at !== null && Date.parse(value.expires_at) <= Date.now();
    draft = Object.fromEntries(value.purposes.map(p => [p.subject, !blocked && !expired && p.decided && p.granted]));
    publish(permit(value, store?.read() ?? null, blocked));
  }

  async function refresh(preserveFailure = false): Promise<void> {
    if (!api || !store || !mounted) return;
    const revision = ++generation;
    if (!preserveFailure) failure = null;
    try {
      let credential = store.read();
      let value: ConsentState;
      try {
        value = await api.state(credential);
      } catch (error) {
        if (!(error instanceof ConsentHttpError) || error.status !== 401 || credential === null) throw error;
        // A server-secret rotation or invalid credential requires a fresh explicit choice.
        if (revision !== generation || !mounted || store.read() !== credential) return;
        publish(null);
        store.clearCredential();
        credential = null;
        value = await api.state(null);
      }
      if (revision !== generation || !mounted) return;
      if (store.read() !== credential) {
        publish(null);
        void refresh(preserveFailure);
        return;
      }
      apply(value);
    } catch {
      if (revision !== generation || !mounted) return;
      publish(null);
      consent = null;
      blocked = true;
      failure = 'unavailable';
    }
  }

  function externalChange(event: StorageEvent): void {
    if (!store || (event.key !== null && event.key !== store.key && event.key !== store.key + '.blocked')) return;
    // No stale UI selection may be saved against the changed browser state.
    generation++;
    publish(null);
    blocked = true;
    if (busy) {
      changedDuringSave = true;
      return;
    }
    void refresh();
  }

  function revalidate(): void {
    if (document.visibilityState === 'visible' && !busy) void refresh();
  }

  onMount(() => {
    mounted = true;
    publish(null);
    try {
      api = new ConsentApi(scope, apiOrigin, fetcher);
      store = new ConsentStorage(scope, window.localStorage);
      window.addEventListener('storage', externalChange);
      document.addEventListener('visibilitychange', revalidate);
      void refresh();
      refreshTimer = window.setInterval(revalidate, 60000);
    } catch {
      failure = 'unavailable';
      blocked = true;
    }
    return () => {
      mounted = false;
      generation++;
      publish(null);
      if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
      window.removeEventListener('storage', externalChange);
      document.removeEventListener('visibilitychange', revalidate);
    };
  });

  async function save(mode: 'accept' | 'refuse' | 'custom'): Promise<void> {
    if (!api || !store || !consent?.enabled || !consent.purposes.length || busy) return;
    const current = consent;
    const choices = selection(current, p => mode === 'accept' || (mode === 'custom' && draft[p.subject] === true));
    busy = true;
    changedDuringSave = false;
    generation++;
    failure = null;
    // Stop consumers synchronously, even if localStorage or the network fails.
    publish(null);
    blocked = true;
    try {
      const credential = store.read();
      store.block();
      blocked = true;
      const value = await api.save(credential, current.version, choices);
      if (!mounted) return;
      if (changedDuringSave || store.read() !== credential) {
        failure = 'conflict';
        return;
      }
      if (!value.enabled || !value.complete || value.version === null) throw new Error('Unconfirmed consent.');
      const next = value.credential ?? credential;
      if (!next) throw new Error('Missing consent credential.');
      // Never clear the local block if the catalogue or effective choices changed.
      if (value.purposes.length !== Object.keys(choices).length
        || value.purposes.some(p => choices[p.subject]?.revision !== p.revision || choices[p.subject]?.granted !== p.granted)) {
        failure = 'conflict';
        return;
      }
      store.commit(next);
      apply(value);
      expanded = false;
    } catch (error) {
      if (mounted) failure = error instanceof ConsentHttpError && error.status === 409 ? 'conflict' : 'blocked';
    } finally {
      busy = false;
      if (mounted && failure !== null) await refresh(true);
    }
  }

  function customize(): void {
    expanded = true;
  }
</script>

{#if offered && !show}
  <button type="button" class="preferences" onclick={customize}>{label('preferences')}</button>
{/if}
{#if show && consent}
  <section class="consent-panel" aria-label={label('title')}>
    <h2>{label('title')}</h2>
    <p>{label('intro')}</p>
    {#if failure}<p class="feedback" role="alert">{label(failure)}</p>{/if}
    {#if expanded}
      <fieldset disabled={busy}>
        <legend>{label('customize')}</legend>
        {#each consent.purposes as purpose (purpose.subject)}
          <div class="purpose">
            <label><input type="checkbox" bind:checked={draft[purpose.subject]} /> <strong>{purpose.name}</strong></label>
            <p>{purpose.purpose} : {purpose.description}</p>
            <!-- eslint-disable-next-line svelte/no-navigation-without-resolve -->
            <a href={purpose.privacy_url} target="_blank" rel="noopener noreferrer">{label('privacy')}</a>
          </div>
        {/each}
      </fieldset>
    {/if}
    <div class="actions">
      <button type="button" disabled={busy} onclick={() => void save('accept')}>{label('accept')}</button>
      <button type="button" disabled={busy} onclick={() => void save('refuse')}>{label('refuse')}</button>
      {#if expanded}
        <button type="button" disabled={busy} onclick={() => void save('custom')}>{label('save')}</button>
        {#if !required}<button type="button" disabled={busy} onclick={() => expanded = false}>{label('cancel')}</button>{/if}
      {:else}
        <button type="button" disabled={busy} onclick={customize}>{label('customize')}</button>
      {/if}
    </div>
    {#if busy}<p role="status">{label('saving')}</p>{/if}
  </section>
{:else if failure}
  <section class="consent-panel" aria-label={label('title')}>
    <p role="alert">{label(failure)}</p>
    <button type="button" onclick={() => void refresh()}>{label('retry')}</button>
  </section>
{/if}

<style>
  .consent-panel { position: fixed; inset-inline: max(1rem, calc((100vw - 48rem) / 2)); bottom: 1rem; z-index: 1000; max-height: calc(100dvh - 2rem); overflow: auto; padding: 1.25rem; border-radius: .75rem; border: 1px solid #6b7280; background: Canvas; color: CanvasText; box-shadow: 0 .25rem 1.5rem #0003; text-align: start; }
  h2 { margin: 0 0 .5rem; font-size: 1.15rem; }
  p { margin: .5rem 0; line-height: 1.5; }
  .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
  button { font: inherit; padding: .6rem .85rem; border: 1px solid currentColor; border-radius: .4rem; background: Canvas; color: CanvasText; cursor: pointer; }
  button:disabled { opacity: .55; cursor: default; }
  button:focus-visible, a:focus-visible, input:focus-visible { outline: 2px solid Highlight; outline-offset: 3px; }
  .preferences { position: fixed; bottom: .75rem; inset-inline-end: .75rem; z-index: 999; font-size: .85rem; }
  fieldset { border: 1px solid #6b7280; border-radius: .4rem; margin: .75rem 0; padding: .75rem; }
  .purpose + .purpose { margin-top: .75rem; padding-top: .75rem; border-top: 1px solid #6b7280; }
  .purpose label { display: flex; gap: .5rem; align-items: center; }
  .purpose a { color: LinkText; }
  .feedback { font-weight: 600; }
</style>
