<script lang="ts">
  import { onMount } from 'svelte';
  import { beforeNavigate, afterNavigate } from '$app/navigation';
  import ConsentPanel from './ConsentPanel.svelte';
  import type { Scope } from './consent-client';
  import { ConsentConsumerBridge, type ConsentConsumerFactory } from './consent-consumer';

  let { scope, language = 'fr' }: { scope: Scope; language?: string } = $props();
  const bridge = new ConsentConsumerBridge();
  const apiOrigin = import.meta.env.PUBLIC_API_URL ?? '';
  // Tracking is optional. The exact entry is owned by Tracking and copied by Tooling.
  const factory = import.meta.glob<ConsentConsumerFactory>('../tracking/consent-consumer.ts', {
    eager: true, import: 'createConsentConsumer'
  })['../tracking/consent-consumer.ts'];

  // Register during component initialization, not in a consumer's onMount callback.
  beforeNavigate(navigation => {
    if (navigation.willUnload || !navigation.to || !bridge.requiresDocumentNavigation()) return;
    navigation.cancel();
    window.location.assign(navigation.to.url.href);
  });
  afterNavigate(navigation => {
    bridge.routeChanged(navigation.to?.route.id ?? null, navigation.to?.url ?? new URL(window.location.href));
  });

  onMount(() => {
    bridge.attach(factory, scope, apiOrigin);
    return () => bridge.dispose();
  });
</script>

<ConsentPanel {scope} {language} {apiOrigin} onPermitChange={value => bridge.publish(value)} onSelectionCommitted={() => bridge.selectionCommitted()} />
