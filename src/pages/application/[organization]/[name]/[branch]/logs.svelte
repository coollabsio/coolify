<script>
  import { fetch, configuration } from "@store";
  import Log from "../../../../../components/Application/Logs/Log.svelte";
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
