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
  <div class="max-w-4xl shadow rounded-lg mx-auto">
    <pre
      class="font-mono text-xs font-medium tracking-tighter border-2 rounded-lg bg-white text-gray-600 p-6  whitespace-pre-wrap">
{#each logs as log}
  {log + '\n'}
{/each}
</pre>
  </div>
{/await}
