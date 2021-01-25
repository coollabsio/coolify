<script>
  import { dateOptions } from "../../store.js";
  import { fade } from "svelte/transition";
  export let deploy;
  let opened = false;
</script>

<p
  class="text-xs cursor-pointer py-2 hover:underline"
  class:underline={opened}
  on:click={() => (opened = !opened)}
>
  {new Intl.DateTimeFormat("default", $dateOptions).format(
    new Date(deploy.createdAt)
  )}
</p>
{#if opened}
  <pre
    transition:fade={{ duration: 50 }}
    class="text-xs tracking-tighter text-justify border-2 border-black bg-coolgray-300 text-white p-6 rounded-md whitespace-pre-wrap">
    {#each deploy.events as event}
      {event + '\n'}
    {/each}
 </pre>
{/if}
