<script>
  import { dateOptions, fetch } from "../../store.js";
  import { fade } from "svelte/transition";
  export let deployment;
  let opened = false;
  let logs = [];
  async function loadLogs() {
    return [].concat.apply(
      [],
      await (
        await $fetch(`/api/v1/deployments/logs?deployId=${deployment.deployId}`)
      ).map((log) => log.events)
    );
  }
</script>

<p
  class="text-xs cursor-pointer py-2 hover:underline"
  class:underline={opened}
  on:click={() => (opened = !opened)}
>
  {new Intl.DateTimeFormat("default", $dateOptions).format(
    new Date(deployment.createdAt)
  )}
</p>
{#if opened}
  {#await loadLogs()}
    Loading...
  {:then logs}
    <pre
      transition:fade={{ duration: 50 }}
      class="text-xs tracking-tighter text-justify border-2 border-black bg-coolgray-300 text-white p-6 rounded-md whitespace-pre-wrap">
{#each logs as log}
  {log + '\n'}
{/each}
</pre>
  {/await}
{/if}
