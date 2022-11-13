<script>
  export let unconfigured
  export let what
</script>
{#if unconfigured}
  <button
    class="btn btn-sm"
    class:loading={loading.databases}
    disabled={loading.databases}
    on:click={() => cleanup(what)}
  >
    Cleanup Unconfigured Resources
  </button>
{/if}