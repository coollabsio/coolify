<script>
  import { SvelteToast } from "@zerodevx/svelte-toast";
  import { Router } from "@roxi/routify";
  import { routes } from "../.routify/routes";
  const options = {
    duration: 1500,
  };
</script>
<style lang="postcss">
  :global(input) {
    @apply border text-sm rounded py-2 px-2 bg-white !important;
  }
  :global(select) {
    @apply border text-sm rounded py-2 px-2 bg-white !important;
  }
  :global(label) {
    @apply text-left text-xs font-bold !important;
  }
  :global(.button) {
    @apply rounded text-xs font-bold transition-all duration-100 !important;
  }
  :global(.h-271) {
    min-height: 271px !important;
  }
</style>

<SvelteToast options="{options}" />
<Router routes="{routes}" />
