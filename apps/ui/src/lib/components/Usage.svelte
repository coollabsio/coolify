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
	import { addToast, appSession } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import Trend from './Trend.svelte';
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

	let warning = {
		memory: false,
		cpu: false,
		disk: false
	};
	let trends = {
		memory: 'stable',
		cpu: 'stable',
		disk: 'stable'
	};
	async function manuallyCleanupStorage() {
		try {
			loading.cleanup = true
			await post('/internal/cleanup', {});
			return addToast({
				message: "Cleanup done.",
				type:"success"
			})
		} catch(error) {
			return errorNotification(error);
		} finally {
			loading.cleanup = false
		}
	}
</script>

{#if $appSession.teamId === '0'}
	<div class="px-6 text-2xl font-bold">Server Usage</div>
	<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Total Memory</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{(usage?.memory.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
			</dd>
		</div>

		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Used Memory</dt>
			<dd class="mt-1 text-3xl font-semibold text-white ">
				{(usage?.memory.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
			</dd>
		</div>

		<div
			class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
			class:bg-red-500={warning.memory}
		>
			<dt class="truncate text-sm font-medium text-white">Free Memory</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.memory.freeMemPercentage}<span class="text-sm">%</span>
				{#if !warning.memory}
					<Trend trend={trends.memory} />
				{/if}
			</dd>
		</div>
	</dl>
	<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Total CPUs</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.cpu.count}
			</dd>
		</div>
		<div
			class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
			class:bg-red-500={warning.cpu}
		>
			<dt class="truncate text-sm font-medium text-white">CPU Usage</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.cpu.usage}<span class="text-sm">%</span>
				{#if !warning.cpu}
					<Trend trend={trends.cpu} />
				{/if}
			</dd>
		</div>
		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Load Average (5/10/30mins)</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.cpu.load.join('/')}
			</dd>
		</div>
	</dl>
	<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Total Disk</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.disk.totalGb}<span class="text-sm">GB</span>
			</dd>
		</div>
		<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
			<dt class="truncate text-sm font-medium text-white">Used Disk</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.disk.usedGb}<span class="text-sm">GB</span>
			</dd>
			<button on:click={manuallyCleanupStorage} class:loading={loading.cleanup} class="btn btn-sm"
				>Cleanup Storage</button
			>
		</div>
		<div
			class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
			class:bg-red-500={warning.disk}
		>
			<dt class="truncate text-sm font-medium text-white">Free Disk</dt>
			<dd class="mt-1 text-3xl font-semibold text-white">
				{usage?.disk.freePercentage}<span class="text-sm">%</span>
				{#if !warning.disk}
					<Trend trend={trends.disk} />
				{/if}
			</dd>
		</div>
	</dl>

	<div class="px-6 pt-20 text-2xl font-bold">Resources</div>
{/if}
