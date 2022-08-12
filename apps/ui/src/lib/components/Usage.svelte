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

{#if $appSession.teamId === '0'}
	<div class="pb-4">
		<div class="title">Hardware Details</div>
		<div class="text-center p-8 ">
			<div>
				<div class="stat w-64">
					<div class="stat-title">Total Memory</div>
					<div class="stat-value">
						{(usage?.memory.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">Used Memory</div>
					<div class="stat-value">
						{(usage?.memory.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">Free Memory</div>
					<div class="stat-value">
						{usage?.memory.freeMemPercentage}<span class="text-sm">%</span>
					</div>
				</div>
			</div>
			<div class="py-10">
				<div class="stat w-64">
					<div class="stat-title">Total CPUs</div>
					<div class="stat-value">
						{usage?.cpu.count}
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">CPU Usage</div>
					<div class="stat-value">
						{usage?.cpu.usage}<span class="text-sm">%</span>
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">Load Average (5,10,30mins)</div>
					<div class="stat-value">{usage?.cpu.load}</div>
				</div>
			</div>
			<div>
				<div class="stat w-64">
					<div class="stat-title">Total Disk</div>
					<div class="stat-value">
						{usage?.disk.totalGb}<span class="text-sm">GB</span>
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">Used Disk</div>
					<div class="stat-value">
						{usage?.disk.usedGb}<span class="text-sm">GB</span>
					</div>
				</div>
				<div class="stat w-64">
					<div class="stat-title">Free Disk</div>
					<div class="stat-value">{usage?.disk.freePercentage}<span class="text-sm">%</span></div>
				</div>
			</div>
		</div>
	</div>
{/if}
