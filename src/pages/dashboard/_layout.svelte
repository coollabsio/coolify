<script>
  import { fetch, deployments } from "@store";
  import { onDestroy, onMount } from "svelte";
  import { goto, params } from "@roxi/routify/runtime";
  import { toast } from "@zerodevx/svelte-toast";
  // import Applications from "../../components/Dashboard/Applications.svelte";
  // import Databases from "../../components/Dashboard/Databases.svelte";
  import { url, isActive } from "@roxi/routify";

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

<nav
  class="mx-auto bg-coolgray-300 border-b-4 text-white mb-3 sm:px-4"
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
    {#if $isActive("/dashboard/applications")}
      <li>
        <button
          class="bg-green-500 hover:bg-green-400 font-bold cursor-pointer rounded-lg text-white"
          on:click="{() => $goto('/application/new/start/main/configuration')}"
        >
          <svg
            class="w-6"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
        </button>
      </li>
    {/if}
    {#if $isActive("/dashboard/databases")}
      <li>
        <button
          class="bg-purple-500 hover:bg-purple-400 font-bold cursor-pointer rounded-lg text-white"
          on:click="{() => $goto('/database/new')}"
        >
          <svg
            class="w-6"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
        </button>
      </li>
    {/if}
  </ul>
</nav>

<slot />
