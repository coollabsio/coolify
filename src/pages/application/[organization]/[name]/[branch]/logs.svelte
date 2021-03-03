<script>
  import { onDestroy } from "svelte";
  import { fetch, configuration } from "@store";
  import { params } from "@roxi/routify";
  import Log from "../../../../../components/Application/Logs/Log.svelte";

  $: organization = $params.organization;
  $: name = $params.name;
  $: branch = $params.branch;

  let deployments = [];
  let branches;
  let selectedBranch;
  let initialSelectedBranch = "Select a branch";

  async function loadDeployments() {
    deployments = await $fetch(
      `/api/v1/application/logs?repoId=${$configuration.repository.id}`,
    );
    branches = [...new Set(deployments.map(log => log.branch))];
  }
</script>

<div>
  {#await loadDeployments() then notUsed}
    <!-- <div class="flex justify-center items-end">
        <button
          class="flex items-center justify-center h-8 w-8 bg-green-600 border border-black rounded-md text-white hover:bg-green-500"
          on:click="{loadDeployments}"
          ><svg
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
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            ></path>
          </svg></button
        >
      </div> -->

    {#if branches.length > 0}
      <div
        class="text-center space-y-2 px-0 md:px-10 xl:px-0 max-w-7xl md:mx-auto mx-6 pb-4"
      >
        <!-- svelte-ignore a11y-no-onchange -->
        <select
          class="mb-6"
          bind:value="{selectedBranch}"
          on:change="{() => (selectedBranch = selectedBranch)}"
        >
          <option selected disabled>{initialSelectedBranch}</option>
          {#each branches as branch}
            <option value="{branch}">{branch}</option>
          {/each}
        </select>

        {#each deployments.filter(l => l.branch === selectedBranch) as deployment}
          <Log deployment="{deployment}" />
        {/each}
      </div>
    {:else}
      <div
        class="text-center space-y-2 max-w-2xl md:mx-auto mx-6 pb-4 font-bold"
      >
        No logs found
      </div>
    {/if}
  {:catch error}
    <div class="flex justify-center items-end pb-6">
      <div class="text-4xl font-bold tracking-tight  px-2 text-center">
        Logs
      </div>
    </div>
    <div class="text-center space-y-2 max-w-2xl md:mx-auto mx-6 pb-4 font-bold">
      No logs found
    </div>
  {/await}
</div>
