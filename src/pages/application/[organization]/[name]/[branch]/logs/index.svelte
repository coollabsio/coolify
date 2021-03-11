<script>
  import { fetch, configuration, dateOptions } from "@store";
  import { fade } from "svelte/transition";
  import { goto } from "@roxi/routify";
  import { onDestroy, onMount } from "svelte";

  import Loading from "../../../../../../components/Loading.svelte";

  let loadDeploymentsInterval = null;
  let deployments = [];

  onMount(() => {
    loadDeploymentsInterval = setInterval(() => {
      loadDeployments();
    }, 1000);
  });
  onDestroy(() => {
    clearInterval(loadDeploymentsInterval);
  });
  async function loadDeployments() {
    deployments = await $fetch(
      `/api/v1/application/logs?repoId=${$configuration.repository.id}&branch=${$configuration.repository.branch}`,
    );
  }
</script>

<div
  class="text-center space-y-2 max-w-4xl md:mx-auto mx-6"
  in:fade="{{ duration: 100 }}"
>
  {#await loadDeployments()}
    <Loading />
  {:then}
    {#if deployments.length > 0}
      {#each deployments as deployment}
        <div
          class="flex space-x-4 text-md py-4  hover:shadow max-w-4xl mx-auto cursor-pointer transition-all duration-100 border-l-4 border-transparent rounded"
          class:hover:border-green-500={deployment.progress === 'done'}
          class:hover:bg-green-100={deployment.progress === 'done'}
          class:border-yellow-500={deployment.progress !== 'done' && deployment.progress !== 'failed'}
          class:hover:bg-yellow-200={deployment.progress !== 'done' && deployment.progress !== 'failed'}
          class:bg-yellow-100={deployment.progress !== 'done' && deployment.progress !== 'failed'}
          class:bg-white={deployment.progress !== 'done' && deployment.progress !== 'failed'}
          class:shadow={deployment.progress !== 'done' && deployment.progress !== 'failed'}
          class:hover:bg-red-200={deployment.progress === 'failed'}
          class:border-red-500={deployment.progress === 'failed'}
          on:click="{() => $goto(`./${deployment.deployId}`)}"
        >
          <div class="font-bold text-sm px-3 flex justify-center items-center">
            {deployment.branch}
          </div>
          <div class="flex-1"></div>
          <div class="px-3 w-48">
            <div
              class="text-xs"
              title="{new Intl.DateTimeFormat('default', $dateOptions).format(
                new Date(deployment.createdAt),
              )}"
            >
              {deployment.since}
            </div>
            {#if deployment.progress === 'done'}
            <div class="text-xs">
              Deployed in <span class="font-bold">{deployment.took}s</span>
            </div>
            {:else if deployment.progress === 'failed'}
            <div class="text-xs">
              Failed
            </div>
            {:else}
            <div class="text-xs">
              Deploying...
            </div>
            {/if}
          </div>
        </div>
      {/each}
    {:else}
      <div
        class="text-center font-bold tracking-tight text-xl"
      >
        No logs found
      </div>
    {/if}
  {:catch}
    <div
      class="text-center font-bold tracking-tight text-xl"
    >
      No logs found
    </div>
  {/await}
</div>
