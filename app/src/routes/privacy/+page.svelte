<script lang="ts">
  import PrivacyPolicy from '../../PrivacyPolicy.svelte';
  import { uiLanguage } from '../../../../api/locales';
  const language = $derived($uiLanguage ?? (typeof navigator === 'undefined' ? 'fr' : navigator.language));
</script>
<PrivacyPolicy scope="app" {language} />
