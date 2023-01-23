<script lang="ts">
	export let id: any;
	import { status } from '$lib/store';
	let serviceStatus = {
		isExcluded: false,
		isExited: false,
		isRunning: false,
		isRestarting: false,
		isStopped: false
	};

	$: if (Object.keys($status.service.statuses).length > 0 && $status.service.statuses[id]?.status) {
		let { isExited, isRunning, isRestarting, isExcluded } = $status.service.statuses[id].status;
		
		serviceStatus.isExited = isExited;
		serviceStatus.isRunning = isRunning;
		serviceStatus.isExcluded = isExcluded;
		serviceStatus.isRestarting = isRestarting;
		serviceStatus.isStopped = !isExited && !isRunning && !isRestarting;
	} else {
		serviceStatus.isExited = false;
		serviceStatus.isRunning = false;
		serviceStatus.isExcluded = false;
		serviceStatus.isRestarting = false;
		serviceStatus.isStopped = true;
	}
</script>

{#if serviceStatus.isExcluded}
	<span class="badge font-bold uppercase rounded text-orange-500 mt-2">Excluded</span>
{:else if serviceStatus.isRunning}
	<span class="badge font-bold uppercase rounded text-green-500 mt-2">Running</span>
{:else if serviceStatus.isStopped || serviceStatus.isExited}
	<span class="badge font-bold uppercase rounded text-red-500 mt-2">Stopped</span>
{:else if serviceStatus.isRestarting}
	<span class="badge font-bold uppercase rounded text-yellow-500 mt-2">Restarting</span>
{/if}
