<script>
  export let unconfigured
  export let what
  import {cleanup} from '$lib/api/cleanup'
</script>
{#if unconfigured}
  <button class="btn btn-sm" on:click={() => cleanup(what)} >
    Cleanup unconfigured {what}
  </button>
{/if}