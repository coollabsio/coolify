<script>
  import { fetch, configuration, dateOptions } from "@store";
  import { fade } from "svelte/transition";
  import { goto } from "@roxi/routify";
  import { onDestroy, onMount } from "svelte";

  import Loading from "../../../../../../components/Loading.svelte";

  let loadDeploymentsInterval = null;
  let loadLogsInterval = null;
  let deployments = [];
  let logs = [];
  let page = 1
  onMount(async () => {
    loadApplicationLogs();
    loadLogsInterval = setInterval(() => {
      loadApplicationLogs();
    }, 3000);
    loadDeploymentsInterval = setInterval(() => {
      loadDeploymentLogs();
    }, 1000);
  });
  onDestroy(() => {
    clearInterval(loadDeploymentsInterval);
    clearInterval(loadLogsInterval);
  });
  async function loadMoreDeploymentLogs() {
    page = page + 1
    await loadDeploymentLogs()
  }
  async function loadDeploymentLogs() {
    deployments = await $fetch(
      `/api/v1/application/deploy/logs?repoId=${$configuration.repository.id}&branch=${$configuration.repository.branch}&page=${page}`,
    );
  }
  async function loadApplicationLogs() {
    logs = (
      await $fetch(
        `/api/v1/application/logs?name=${$configuration.build.container.name}`,
      )
    ).logs;
  }
</script>

<style lang="postcss">
  .w-300{
    width: 300px !important;
  }
</style>
<div
  class="text-center space-y-2 max-w-6xl md:mx-auto mx-6"
  in:fade="{{ duration: 100 }}"
>
  {#await loadDeploymentLogs()}
    <Loading />
  {:then}
    <div class="flex pt-2 space-x-4 w-full">
      <div class="w-full">
        <div class="font-bold text-left pb-2 tracking-tighter text-xl">
          Application logs
        </div>
        {#if logs.length === 0}
          <div class="text-xs">Waiting for the logs...</div>
          {:else}
          <pre
          class="border-l-4 border-r-4 border-green-500 text-left font-mono text-xs font-medium tracking-tighter rounded text-gray-200 bg-black p-4 whitespace-pre-wrap w-full">
            {#each logs as log}
              {log + '\n'}
            {/each}
          </pre>
        {/if}
      </div>
      <div>
          <div class="font-bold text-left pb-2 tracking-tighter text-xl w-300">
            Deployment logs
          </div>
          {#if deployments.length > 0}
          {#each deployments as deployment}
            <div
            in:fade="{{ duration: 100 }}"
              class="flex space-x-4 text-md py-4 hover:shadow  mx-auto cursor-pointer transition-all duration-100 border-l-4 border-transparent rounded"
              class:hover:border-green-500="{deployment.progress === 'done'}"
              class:hover:bg-green-100="{deployment.progress === 'done'}"
              class:border-yellow-500="{deployment.progress !== 'done' &&
                deployment.progress !== 'failed'}"
              class:hover:bg-yellow-200="{deployment.progress !== 'done' &&
                deployment.progress !== 'failed'}"
              class:bg-yellow-100="{deployment.progress !== 'done' &&
                deployment.progress !== 'failed'}"
              class:bg-white="{deployment.progress !== 'done' &&
                deployment.progress !== 'failed'}"
              class:shadow="{deployment.progress !== 'done' &&
                deployment.progress !== 'failed'}"
              class:hover:bg-red-200="{deployment.progress === 'failed'}"
              class:border-red-500="{deployment.progress === 'failed'}"
              on:click="{() => $goto(`./${deployment.deployId}`)}"
            >
              <div
                class="font-bold text-sm px-3 flex justify-center items-center"
              >
                {deployment.branch}
              </div>
              <div class="flex-1"></div>
              <div class="px-3 w-48">
                <div
                  class="text-xs"
                  title="{new Intl.DateTimeFormat(
                    'default',
                    $dateOptions,
                  ).format(new Date(deployment.createdAt))}"
                >
                  {deployment.since}
                </div>
                {#if deployment.progress === "done"}
                  <div class="text-xs">
                    Deployed in <span class="font-bold">{deployment.took}s</span
                    >
                  </div>
                {:else if deployment.progress === "failed"}
                  <div class="text-xs">Failed</div>
                {:else}
                  <div class="text-xs">Deploying...</div>
                {/if}
              </div>
            </div>
          {/each}
          <button
          class="text-xs bg-green-600 hover:bg-green-500 p-1 rounded text-white px-2 font-bold my-6"
          on:click={loadMoreDeploymentLogs}
          >Show more</button
        >
          {:else}
          <div class="text-left text-sm tracking-tight">
            No deployments found
          </div>
          {/if}
      </div>
    </div>
  {:catch}
    <div class="text-center font-bold tracking-tight text-xl">
      No logs found
    </div>
  {/await}
</div>
