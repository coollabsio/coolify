<script lang="ts">
  import {page} from '$app/stores';
	import PreferencesIcon from '../svg/menu/PreferencesIcon.svelte';
	import Tooltip from '../Tooltip.svelte';
  export let what:string;
  export let id:any;
</script>

<div class="flex flex-row flex-wrap space-x-3 justify-center lg:justify-start lg:py-0">
  <a
    id="configuration"
    href="/{what}/{id}"
    sveltekit:prefetch
    class="hover:text-yellow-500 rounded"
    class:text-yellow-500={$page.url.pathname === `/{what}/${id}`}
    class:bg-coolgray-500={$page.url.pathname === `/{what}/${id}`}
  >
    <button class="icons bg-transparent m text-sm disabled:text-red-500">
        <PreferencesIcon/>
    </button>
  </a>
</div>
  <Tooltip triggeredBy="#configuration">{'Configuration'}</Tooltip>
  