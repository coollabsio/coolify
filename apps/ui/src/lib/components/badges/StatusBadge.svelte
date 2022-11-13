<script type="ts">
  import {getStatus} from '$lib/api/status'
	import { status } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
  export let thing:any;
  let getting = getStatus(thing)
  let refreshing;
  // AutoUpdates Status every 5 seconds
  onMount( ()=>{
    refreshing = setInterval( () =>{
      getStatus(thing).then( (r) => status = r )
    }, 5000)
  })
  onDestroy( () =>{
    clearInterval(refreshing);
  })
</script>
{#await getting}
  <span class="badge badge-lg rounded uppercase">...</span>
{:then status} 
  <span class="badge badge-lg rounded uppercase badge-status-{status}">
    {status}
  </span>  
{/await}
