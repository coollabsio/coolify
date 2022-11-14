<script lang="ts">
  import {page} from '$app/stores';
  import { status } from '$lib/store';
	import LogsIcon from '../svg/menu/LogsIcon.svelte';
	import Tooltip from '../Tooltip.svelte';
  export let what:string;
  export let id:any;
</script>

<a
  id="databaselogs"
  href={$status.database.isRunning ? `/${what}/${id}/logs` : null}
  sveltekit:prefetch
  class="hover:text-pink-500 rounded"
  class:text-pink-500={$page.url.pathname === `/${what}/${id}/logs`}
  class:bg-coolgray-500={$page.url.pathname === `/${what}/${id}/logs`}
>
  <button disabled={!$status.database.isRunning} class="icons bg-transparent text-sm">
    <LogsIcon/>
  </button>
</a>

<Tooltip triggeredBy="#databaselogs">{'Logs'}</Tooltip>     