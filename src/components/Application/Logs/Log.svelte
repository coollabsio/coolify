<script>
  import { dateOptions, fetch } from "@store";
  import { fade } from "svelte/transition";

  export let deployment;
  let opened = false;
  let loadLogsInterval;
  let logs = [];

  async function fetchLogs() {
    opened = !opened;
    if (opened) {
      loadLogsInterval = setInterval(() => {
        loadLogs();
      }, 500);
    } else {
      clearInterval(loadLogsInterval);
      logs = [];
    }
  }

  async function loadLogs() {
    const { events, progress } = await $fetch(
      `/api/v1/application/logs/${deployment.deployId}`,
    );
    logs = [...events];
    if (progress === "done" || progress === "failed") {
      clearInterval(loadLogsInterval);
    }
  }
</script>

<p
  class="text-xs cursor-pointer py-2 hover:underline"
  class:underline="{opened}"
  on:click="{fetchLogs}"
>
  {new Intl.DateTimeFormat("default", $dateOptions).format(
    new Date(deployment.createdAt),
  )}
</p>

{#if opened}
  {#await loadLogs()}
    Loading...
  {:then}
    <pre
      transition:fade="{{ duration: 50 }}"
      class="text-xs tracking-tighter text-justify border-2 bg-gray-100 text-black p-6 rounded-md whitespace-pre-wrap">
    {#each logs as log}
      {log + '\n'}
    {/each}
    </pre>
  {/await}
{/if}
