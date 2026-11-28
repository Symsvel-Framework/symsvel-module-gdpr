<script lang="ts">
  import { onMount } from 'svelte';
  import { beforeNavigate, afterNavigate } from '$app/navigation';
  import { page } from '$app/state';
  import { apiBaseUrl } from '../../api/client';
  import ConsentPanel from './ConsentPanel.svelte';
  import { generatedTranslation } from '../../i18n/generated-translations';
  import fr from '../../translations/gdpr/fr';
  import en from '../../translations/gdpr/en';
  import type { Scope } from './consent-client';
  import { ConsentConsumerBridge, type ConsentConsumerFactory } from './consent-consumer';

  let { scope, language = 'fr' }: { scope: Scope; language?: string } = $props();
  const policyLabel = $derived(generatedTranslation(language, scope + '.gdpr.policyLink')
    ?? (language.toLowerCase().startsWith('en') ? en.policyLink : fr.policyLink));
  const bridge = new ConsentConsumerBridge();
  // Share the host's API resolution; no browser location during static prerender.
  const apiOrigin = typeof window === 'undefined' ? '' : apiBaseUrl();
  // Tracking is optional. The exact entry is owned by Tracking and copied by Tooling.
  const factory = import.meta.glob<ConsentConsumerFactory>('../tracking/consent-consumer.ts', {
    eager: true, import: 'createConsentConsumer'
  })['../tracking/consent-consumer.ts'];

  // Register during component initialization, not in a consumer's onMount callback.
  beforeNavigate(navigation => {
    // Invalidate the previous route before an in-flight projection can start a SDK.
    const fullDocument = bridge.requiresDocumentNavigation();
    bridge.routeChanged(null, new URL(window.location.href));
    if (navigation.willUnload || !navigation.to || !fullDocument) return;
    navigation.cancel();
    window.location.assign(navigation.to.url.href);
  });
  afterNavigate(() => {
    // After a completed navigation (including the initial enter), the current
    // page state is authoritative. The navigation event may omit its destination.
    bridge.routeChanged(page.route.id, new URL(window.location.href));
  });

  onMount(() => {
    // Seed the actual initial route even if the host's first afterNavigate already ran.
    bridge.routeChanged(page.route.id, new URL(window.location.href));
    bridge.attach(factory, scope, apiOrigin);
    return () => bridge.dispose();
  });
</script>

<ConsentPanel {scope} {language} {apiOrigin} onPermitChange={value => bridge.publish(value)} onSelectionCommitted={() => bridge.selectionCommitted()} />

{#if page.route.id !== '/privacy'}
  <!-- The optional module owns this exact route; the host may build without it. -->
  <!-- eslint-disable-next-line svelte/no-navigation-without-resolve -->
  <a class="policy-link" href="/privacy">{policyLabel}</a>
{/if}

<style>
  .policy-link { position: fixed; bottom: .75rem; inset-inline-start: .75rem; z-index: 998; padding: .4rem .6rem; border: 1px solid currentColor; border-radius: .4rem; background: Canvas; color: LinkText; font-size: .8rem; max-width: 45%; }
  .policy-link:focus-visible { outline: 2px solid Highlight; outline-offset: 3px; }
</style>
