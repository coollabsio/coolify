<script lang="ts">
	let usage = {
		cpu: {
			load: [0, 0, 0],
			count: 0,
			usage: 0
		},
		memory: {
			totalMemMb: 0,
			freeMemMb: 0,
			usedMemMb: 0,
			freeMemPercentage: 0
		},
		disk: {
			freePercentage: 0,
			totalGb: 0,
			usedGb: 0
		}
	};
	let usageInterval: any;
	let loading = {
		usage: false,
		cleanup: false
	};
	import { appSession } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/common';
	async function getStatus() {
		if (loading.usage) return;
		loading.usage = true;
		const data = await get('/usage');
		usage = data.usage;
		loading.usage = false;
	}
	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		try {
			if ($appSession.teamId === '0') {
				await getStatus();
				usageInterval = setInterval(async () => {
					await getStatus();
				}, 1000);
			}
		} catch (error) {
			return errorNotification(error);
		}
	});
</script>

<div class="w-full">
	<h1 class="title text-4xl">Hardware details</h1>
	<div class="divider" />
	<div class="grid grid-flow-col gap-4 grid-rows-3 lg:grid-rows-1">
		<div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-5">
			<div class="stat">
				<div class="stat-title">Total Memory</div>
				<div class="stat-value">
					{(usage?.memory.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Memory</div>
				<div class="stat-value">
					{(usage?.memory.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Memory</div>
				<div class="stat-value">
					{usage?.memory.freeMemPercentage}<span class="text-sm">%</span>
				</div>
			</div>
		</div>

		<div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-5">
			<div class="stat">
				<div class="stat-title">Total CPUs</div>
				<div class="stat-value">
					{usage?.cpu.count}
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">CPU Usage</div>
				<div class="stat-value">
					{usage?.cpu.usage}<span class="text-sm">%</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Load Average (5,10,30mins)</div>
				<div class="stat-value">{usage?.cpu.load}</div>
			</div>
		</div>

		<div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-5">
			<div class="stat">
				<div class="stat-title">Total Disk</div>
				<div class="stat-value">
					{usage?.disk.totalGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Disk</div>
				<div class="stat-value">
					{usage?.disk.usedGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Disk</div>
				<div class="stat-value">{usage?.disk.freePercentage}<span class="text-sm">%</span></div>
			</div>
		</div>
	</div>
</div>
