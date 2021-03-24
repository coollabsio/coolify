<script>
  import { SvelteToast } from "@zerodevx/svelte-toast";
  import { Router } from "@roxi/routify";
  import { routes } from "../.routify/routes";
  const options = {
    duration: 2000,
    dismissable: false
  };
</script>

<style lang="postcss">
  :global(._toastMsg) {
    @apply text-sm font-bold !important;
  }
  :global(._toastItem) {
    @apply w-full border-l-2 border-green-600 !important;
  }
  :global(._toastBtn) {
    @apply text-xs !important;
  }
  :global(._toastBtn:hover) {
    @apply bg-gray-500 !important;
  }
  :global(.icon) {
    @apply text-white rounded p-2 transition duration-100 !important;
  }
  :global(.icon:hover) {
    @apply bg-warmGray-700 !important;
  }
  :global(input) {
    @apply text-sm rounded py-2 px-6 font-bold bg-warmGray-800 text-white transition duration-150 outline-none !important;
  }
  :global(input:hover) {
    @apply bg-warmGray-700 !important;
  }
  :global(textarea) {
    @apply text-sm rounded py-2 px-6 font-bold bg-warmGray-800 text-white transition duration-150 outline-none resize-none !important;
  }
  :global(textarea:hover) {
    @apply bg-warmGray-700 !important;
  }
  :global(select) {
    @apply text-sm rounded py-2 px-6 font-bold bg-warmGray-800 text-white transition duration-150 outline-none !important;
  }
  :global(select:hover) {
    @apply bg-warmGray-700 !important;
  }
  :global(label) {
    @apply text-left text-base font-bold text-warmGray-400 !important;
  }
  :global(button) {
    @apply outline-none !important;
  }
  :global(.button) {
    @apply rounded text-sm font-bold transition-all duration-100 !important;
  }
  :global(.h-271) {
    min-height: 271px !important;
  }
</style>

<SvelteToast options="{options}" />
<Router routes="{routes}" />
