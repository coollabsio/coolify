<script lang="ts">
  import { get } from '$lib/api';
  
  import { appSession, isDeploymentEnabled, status } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
  import { fade } from 'svelte/transition';
  import {startThing, stopThing} from '$lib/api/onOff';
	import Tooltip from '../Tooltip.svelte';
	import LoadingIcon from '../svg/menu/LoadingIcon.svelte';
	import ForceRedeployIcon from '../svg/menu/ForceRedeployIcon.svelte';
	import StopIcon from '../svg/menu/StopIcon.svelte';
	import StartIcon from '../svg/menu/StartIcon.svelte';

  export let valid:any;
  export let id:any;
  export let what:any;
  export let thing:any;
  
  let statusInterval: any = false;
  let resetStatus = () => {
    return {isRunning: false, isExited: false, loading: false, startup: { } }
  }
  let thingStatus = resetStatus();
  let stSet = (key, val) => {
    thingStatus = Object.assign(thingStatus, {[key]: val})
  };

  $isDeploymentEnabled = $appSession.isAdmin;
  
	onDestroy(() => { thingStatus = resetStatus(); clearInterval(statusInterval); });
	onMount(async () => {
		if (valid) {
			await getStatus();
			statusInterval = setInterval(async () => { await getStatus(); }, 5000);
		}
	});
	async function getStatus() {
		if (thingStatus.loading) return;
		stSet('loading', true);
		const data = await get(`/databases/${id}/status`);
		stSet('isRunning', data.isRunning);
    $status[what.slice(0,-1)].isRunning = data.isRunning;
		stSet('loading', false);
	}
</script>

{#if thingStatus.loading}
  <button class="icons bg-transparent text-sm" in:fade="{{ duration: 600 }}" out:fade="{{ delay: 600, duration: 600 }}">
    <LoadingIcon/>
  </button>
{/if}

{#if thingStatus.isRunning}
  <button id="force-redeploy" disabled={!$isDeploymentEnabled} class="icons bg-transparent text-sm"
    on:click={async () => { await stopThing(thing, what); startThing(thing, what)} } >
    <ForceRedeployIcon/>
  </button>
  <Tooltip triggeredBy="#force-redeploy">Force Redeploy</Tooltip>     
{/if}

{#if thingStatus.isRunning || thingStatus.overallStatus === 'degraded'}
  <button
    id="full-stop"
    on:click={() => stopThing(thing,what)}
    type="submit"
    disabled={!$isDeploymentEnabled}
    class="icons bg-transparent text-sm">
    <StopIcon/>
  </button>
  <Tooltip triggeredBy="#full-stop">Stop</Tooltip>
{/if}

{#if !thingStatus.isRunning}
  <button
  id="go-start"
  on:click={() => startThing(thing,what)}
  type="submit"
  disabled={!$isDeploymentEnabled}
  class="icons bg-transparent text-sm">
  <StartIcon/>
  </button>
  <Tooltip triggeredBy="#go-start">Start</Tooltip>
{/if}