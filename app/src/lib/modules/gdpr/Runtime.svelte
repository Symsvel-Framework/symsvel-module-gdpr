<script lang="ts">
  import { onMount } from 'svelte';
  import { beforeNavigate, afterNavigate } from '$app/navigation';
  import { page } from '$app/state';
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
