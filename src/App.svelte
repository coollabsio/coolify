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
    @apply border text-xs rounded p-1 px-2  !important;
  }
  :global(select) {
    @apply border text-xs rounded p-1 px-2 !important;
  }
  :global(label) {
    @apply text-left text-xs font-bold !important;
  }
  :global(.button) {
    @apply rounded text-xs font-bold transition-all duration-150 !important;
  }

  :global(.h-271) {
    min-height: 271px !important;
  }
</style>

<SvelteToast options="{options}" />
<Router routes="{routes}" />
