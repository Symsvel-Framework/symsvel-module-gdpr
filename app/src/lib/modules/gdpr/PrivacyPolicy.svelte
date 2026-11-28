<script lang="ts">
  import { generatedTranslation } from '../../i18n/generated-translations';
  import fr from '../../translations/gdpr/fr';
  import en from '../../translations/gdpr/en';
  import type { Scope } from './consent-client';

  let { scope, language = 'fr' }: { scope: Scope; language?: string } = $props();
  function label(key: keyof typeof fr): string {
    return generatedTranslation(language, scope + '.gdpr.' + key)
      ?? (language.toLowerCase().startsWith('en') ? en[key] : fr[key]);
  }
  const address = $derived(label('rightsEmail').trim());
  // A configured single public mailbox, never an arbitrary URI or extra headers.
  const validAddress = $derived(address.length <= 254 && /^[A-Za-z0-9._+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/.test(address));
  const contact = $derived(validAddress ? 'mailto:' + address + '?subject=' + encodeURIComponent(label('requestSubject')) : null);
</script>

<svelte:head><title>{label('policyTitle')}</title></svelte:head>
<main class="policy">
  <h1>{label('policyTitle')}</h1>
  <section>
    <h2>{label('dataHeading')}</h2>
    <p>{label('dataText')}</p>
  </section>
  <section>
    <h2>{label('cookiesHeading')}</h2>
    <p>{label('cookiesText')}</p>
  </section>
  <section>
    <h2>{label('rightsHeading')}</h2>
    <p>{label('rightsText')}</p>
    {#if contact}
      <p>
        <!-- Mailto is an external protocol, not a SvelteKit route. -->
        <!-- eslint-disable-next-line svelte/no-navigation-without-resolve -->
        <a href={contact}>{label('contactLabel')} ({address})</a>
      </p>
    {:else}
      <p>{label('contactUnavailable')}</p>
    {/if}
  </section>
</main>

<style>
  .policy { max-width: 52rem; margin: 0 auto; padding: 2rem 1.25rem 8rem; color: CanvasText; background: Canvas; }
  section { margin-block: 2rem; }
  p { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.65; }
  a { color: LinkText; }
  a:focus-visible { outline: 2px solid Highlight; outline-offset: 3px; }
</style>
