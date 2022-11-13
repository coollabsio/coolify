<script lang="ts">
  export let disabled:any;
  export let action:any;
  import Tooltip from "../Tooltip.svelte";
  import DeleteIcon from '$lib/components/DeleteIcon.svelte';
</script>
<button
  id="delete"
  on:click={action}
  disabled={disabled}
  class="icons bg-transparent text-sm h-8 w-8 mr-4"
>
  <slot><DeleteIcon /></slot>
</button>

<Tooltip triggeredBy="#delete" placement="bottom">Delete</Tooltip>
