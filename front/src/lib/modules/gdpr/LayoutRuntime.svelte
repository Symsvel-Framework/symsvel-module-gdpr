<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { Scope } from './consent-client';
  import Runtime from './Runtime.svelte';
  let { scope, language, showFooter = true, children }: { scope: Scope; language?: string; showFooter?: boolean; children: Snippet } = $props();
</script>
<Runtime {scope} {language} {showFooter} {children} />
