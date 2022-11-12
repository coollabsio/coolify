<script>
  export let disabled;
  export let action;
  import {appSession} from '$lib/store';
</script>
<button
  on:click={action}
  disabled={disabled}
  class:bg-red-600={$appSession.isAdmin}
  class:hover:bg-red-500={$appSession.isAdmin}
  class="btn btn-sm btn-error text-sm"
>
  Delete Service
</button>