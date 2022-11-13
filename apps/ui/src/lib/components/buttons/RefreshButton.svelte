<script lang="ts">
  export let things:any;
  export let what:string;
  import {containerStatus, refreshStatus} from '$lib/api/status';
  let body = 'Refresh Status';
</script>

<button 
  class="btn btn-sm btn-primary" 
  on:click={() => refreshStatus(what, things)}
>
  {body}
</button>