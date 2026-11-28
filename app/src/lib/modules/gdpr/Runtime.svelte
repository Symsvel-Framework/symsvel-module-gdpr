<script lang="ts">
  import ConsentPanel from './ConsentPanel.svelte';
  import type { Scope } from './consent-client';

  let { scope, language = 'fr' }: { scope: Scope; language?: string } = $props();
</script>

<ConsentPanel {scope} {language} />
