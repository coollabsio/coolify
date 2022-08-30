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
		cleanup: false,
		restart: false
	};
	import { addToast, appSession } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	async function getStatus() {
		if (loading.usage) return;
		loading.usage = true;
		const data = await get('/usage');
		usage = data.usage;
		loading.usage = false;
	}
	async function restartCoolify() {
		const sure = confirm(
			'Are you sure you would like to restart Coolify? Currently running deployments will be stopped and restarted.'
		);
		if (sure) {
			loading.restart = true;
			try {
				await post(`/internal/restart`, {});
				addToast({
					type: 'success',
					message: 'Coolify restarted successfully. It will take a moment.'
				});
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.restart = false;
			}
		}
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
	async function manuallyCleanupStorage() {
		try {
			loading.cleanup = true;
			await post('/internal/cleanup', {});
			return addToast({
				message: 'Cleanup done.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.cleanup = false;
		}
	}
</script>

<div class="w-full">
	<div class="flex lg:flex-row flex-col gap-4">
		<h1 class="title lg:text-3xl">Hardware Details</h1>
		<div class="flex lg:flex-row flex-col space-x-0 lg:space-x-2 space-y-2 lg:space-y-0">
			{#if $appSession.teamId === '0'}
				<button
					on:click={manuallyCleanupStorage}
					class:loading={loading.cleanup}
					class="btn btn-sm">Cleanup Storage</button
				>
				<button
					on:click={restartCoolify}
					class:loading={loading.restart}
					class="btn btn-sm bg-red-600 hover:bg-red-500">Restart Coolify</button
				>
			{/if}
		</div>
	</div>
	<div class="divider" />
	<div class="grid grid-flow-col gap-4 grid-rows-3 justify-start lg:justify-center lg:grid-rows-1">
		<div class="stats stats-vertical min-w-[16rem] mb-5 rounded bg-transparent">
			<div class="stat">
				<div class="stat-title">Total Memory</div>
				<div class="stat-value text-2xl">
					{(usage?.memory.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Memory</div>
				<div class="stat-value text-2xl">
					{(usage?.memory.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Memory</div>
				<div class="stat-value text-2xl">
					{usage?.memory.freeMemPercentage}<span class="text-sm">%</span>
				</div>
			</div>
		</div>

		<div class="stats stats-vertical min-w-[20rem] mb-5 bg-transparent rounded">
			<div class="stat">
				<div class="stat-title">Total CPU</div>
				<div class="stat-value text-2xl">
					{usage?.cpu.count}
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">CPU Usage</div>
				<div class="stat-value text-2xl">
					{usage?.cpu.usage}<span class="text-sm">%</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Load Average (5,10,30mins)</div>
				<div class="stat-value text-2xl">{usage?.cpu.load}</div>
			</div>
		</div>
		<div class="stats stats-vertical min-w-[16rem] mb-5 bg-transparent rounded">
			<div class="stat">
				<div class="stat-title">Total Disk</div>
				<div class="stat-value text-2xl">
					{usage?.disk.totalGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Disk</div>
				<div class="stat-value text-2xl">
					{usage?.disk.usedGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Disk</div>
				<div class="stat-value text-2xl">
					{usage?.disk.freePercentage}<span class="text-sm">%</span>
				</div>
			</div>
		</div>
	</div>
</div>
