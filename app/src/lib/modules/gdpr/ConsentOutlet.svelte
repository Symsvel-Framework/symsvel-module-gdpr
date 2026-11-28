<script lang="ts">
  import { getContext, type Snippet } from 'svelte';
  const content = getContext<Snippet | undefined>('symsvel.gdpr.preferences');
</script>
{@render content?.()}
