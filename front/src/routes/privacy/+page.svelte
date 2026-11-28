<script lang="ts">
  import PrivacyPolicy from '../../PrivacyPolicy.svelte';
  const language = typeof navigator === 'undefined' ? 'fr' : navigator.language;
</script>
<PrivacyPolicy scope="front" {language} />
