<script>
  import { params } from "@roxi/routify";
  import { onDestroy, onMount } from "svelte";
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
      `/api/v1/application/logs/${$params.deployId}`,
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

{#await loadLogs()}
  <Loading />
{:then}
  <div class="max-w-4xl mx-auto">
    <pre
      class="font-mono text-xs font-medium tracking-tighter rounded-lg text-gray-600 p-6  whitespace-pre-wrap">
      {#if logs.length > 0}
        {#each logs as log}
          {log + '\n'}
        {/each}
      {:else}
        It's starting soon.
      {/if}
    </pre>
  </div>
{/await}
