<script>
  import { params } from "@roxi/routify";
  import { onDestroy, onMount } from "svelte";
  import { fade } from "svelte/transition";
  import { fetch } from "@store";
  import Loading from "../../../../../../components/Loading.svelte";

  let loadLogsInterval;
  let logs = [];

  onMount(() => {
    loadLogsInterval = setInterval(() => {
      loadLogs();
    }, 500);
  });

  async function loadLogs() {
    const { events, progress } = await $fetch(
      `/api/v1/application/deploy/logs/${$params.deployId}`,
    );
    logs = [...events];
    if (progress === "done" || progress === "failed") {
      clearInterval(loadLogsInterval);
    }
  }
  onDestroy(() => {
    clearInterval(loadLogsInterval);
  });
</script>

<div
  class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
  in:fade="{{ duration: 100 }}"
>
  <div>Deployment log</div>
</div>
{#await loadLogs()}
  <Loading />
{:then}
  <div
    class="text-center space-y-2 max-w-4xl md:mx-auto mx-6"
    in:fade="{{ duration: 100 }}"
  >
    <div class="max-w-4xl mx-auto" in:fade="{{ duration: 100 }}">
      <pre
        class="text-left font-mono text-xs font-medium tracking-tighter rounded-lg bg-warmGray-800  p-4 whitespace-pre-wrap">
      {#if logs.length > 0}
        {#each logs as log}
          {log + '\n'}
        {/each}
      {:else}
        It's starting soon.
      {/if}
    </pre>
    </div>
  </div>
{/await}
