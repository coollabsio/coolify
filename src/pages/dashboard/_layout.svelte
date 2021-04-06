<script>
  import { fetch, deployments } from "@store";
  import { onDestroy, onMount } from "svelte";
  import { fade } from "svelte/transition";
  import { goto, isActive } from "@roxi/routify/runtime";
  import { toast } from "@zerodevx/svelte-toast";
  let loadDashboardInterval = null;

  async function loadDashboard() {
    try {
      $deployments = await $fetch(`/api/v1/dashboard`);
    } catch (error) {
      toast.push(error?.error || error);
    }
  }

  onMount(() => {
    loadDashboard();
    loadDashboardInterval = setInterval(() => {
      loadDashboard();
    }, 2000);
  });

  onDestroy(() => {
    clearInterval(loadDashboardInterval);
  });

</script>

<div class="min-h-full text-white">
  asd
  <slot />
</div>
