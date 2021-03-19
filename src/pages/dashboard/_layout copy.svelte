<script>
  import { fetch, deployments } from "@store";
  import { onDestroy, onMount } from "svelte";
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
  function newThing() {
    const gotoUrl = $isActive("/dashboard/applications")
      ? "/application/new"
      : "/database/new";
    $goto(gotoUrl);
  }
</script>

<nav
  class="mx-auto bg-coolgray-300 border-b-4 text-white mb-3 sm:px-4 transition-all duration-250"
  class:border-green-500="{$isActive('/dashboard/applications')}"
  class:border-purple-500="{$isActive('/dashboard/databases')}"
>
  <ul class="flex space-x-4 justify-center h-10 max-w-4xl mx-auto">
    <li>
      <button
        class="hover:text-green-400 font-bold text-sm cursor-pointer"
        class:text-green-400="{$isActive('/dashboard/applications')}"
        on:click="{() => $goto('/dashboard/applications')}"
      >
        Applications
      </button>
    </li>
    <li>
      <button
        class="hover:text-purple-400 font-bold text-sm cursor-pointer"
        class:text-purple-400="{$isActive('/dashboard/databases')}"
        on:click="{() => $goto('/dashboard/databases')}"
      >
        Databases
      </button>
    </li>
    <li class="flex-1 hidden lg:flex"></li>
    <li>
      <button
        class:bg-green-600="{$isActive('/dashboard/applications')}"
        class:hover:bg-green-500="{$isActive('/dashboard/applications')}"
        class:bg-purple-500="{$isActive('/dashboard/databases')}"
        class:hover:bg-purple-400="{$isActive('/dashboard/databases')}"
        class="button px-4 py-1 cursor-pointer transition-all duration-250"
        on:click="{newThing}"
      > Add </button>
    </li>
  </ul>
</nav>

<slot />
