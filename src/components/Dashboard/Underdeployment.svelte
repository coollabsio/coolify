<script>
  import { onDestroy } from "svelte";
  import { fetch, deployments } from "@store";
  export let deployment;
  let logs = [];
  let watching = false;
  let interval = null;

  onDestroy(() => {
    clearInterval(interval);
  });
  function startWatchingLogs(deployment) {
    watching = !watching;
    if (!watching) {
      logs = [];
      clearInterval(interval);
    } else {
      getLogs(deployment.deployId);
    }
  }

  async function getLogs(deployId) {
    watching = true;
    logs = [].concat.apply(
      [],
      await (await $fetch(`/api/v1/application/logs?deployId=${deployId}`)).map(
        log => log.event,
      ),
    );
    interval = setInterval(async () => {
      logs = [].concat.apply(
        [],
        await (
          await $fetch(`/api/v1/application/logs?deployId=${deployId}`)
        ).map(log => log.event),
      );
      if ($deployments.applications.underDeployment.length === 0) {
        watching = false;
        logs = [];
        clearInterval(interval);
      }
    }, 500);
  }
</script>

<div class="flex flex-row flex-wrap gap-4 justify-center items-center mx-6">
  <div class="text-xs">{deployment.domain}</div>
  <button
    class="flex items-center justify-center h-8 w-8"
    class:text-red-600="{watching}"
    on:click="{() => startWatchingLogs(deployment)}"
  >
    {#if !watching}
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
          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
        ></path>
      </svg>
    {:else}
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
          d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
        ></path>
      </svg>
    {/if}
  </button>
</div>
{#if logs.length > 0}
  <pre
    class="text-xs tracking-tighter text-justify border-2 border-black bg-coolgray-300 text-white p-6 rounded-md whitespace-pre-wrap">
{#each logs as log}
{log + '\n'}
{/each}
</pre>
{/if}
