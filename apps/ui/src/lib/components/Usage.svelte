<script lang="ts">
	export let server: any;
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
	async function getStatus() {
		if (loading.usage) return;
		loading.usage = true;
		const data = await get(`/servers/usage/${server.id}?remoteEngine=${server.remoteEngine}`);
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
	async function manuallyCleanupStorage() {
		try {
			loading.cleanup = true;
			await post('/internal/cleanup', { serverId: server.id });
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

<div class="w-full relative p-5 ">
	{#if loading.usage}
		<span class="indicator-item badge bg-yellow-500 badge-sm" />
	{:else}
		<span class="indicator-item badge bg-success badge-sm" />
	{/if}
	{#if server.remoteEngine}
		<div
			class="absolute top-0 right-0 text-xl font-bold uppercase bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 p-1 rounded m-2"
		>
			BETA
		</div>
	{/if}
	<div class="w-full flex flex-row space-x-4">
		<div class="flex flex-col">
			<h1 class="font-bold text-lg lg:text-xl truncate">
				{server.name}
			</h1>
			<div class="text-xs ">
				{#if server?.remoteIpAddress}
					<h2>{server?.remoteIpAddress}</h2>
				{:else}
					<h2>localhost</h2>
				{/if}
			</div>
		</div>
		{#if $appSession.teamId === '0'}
			<button
				on:click={manuallyCleanupStorage}
				class:loading={loading.cleanup}
				class="btn btn-sm bg-coollabs">Cleanup Storage</button
			>
		{/if}
	</div>
	<div class="flex lg:flex-row flex-col gap-4">
		<div class="flex lg:flex-row flex-col space-x-0 lg:space-x-2 space-y-2 lg:space-y-0" />
	</div>
	<div class="divider" />
	<div class="grid grid-flow-col gap-4 grid-rows-3 justify-start lg:justify-center lg:grid-rows-1">
		<div class="stats stats-vertical min-w-[16rem] mb-5 rounded bg-transparent">
			<div class="stat">
				<div class="stat-title">Total Memory</div>
				<div class="stat-value text-2xl text-white">
					{(usage?.memory?.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Memory</div>
				<div class="stat-value text-2xl text-white">
					{(usage?.memory?.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Memory</div>
				<div class="stat-value text-2xl text-white">
					{(usage?.memory?.freeMemPercentage).toFixed(0)}<span class="text-sm">%</span>
				</div>
			</div>
		</div>

		<div class="stats stats-vertical min-w-[20rem] mb-5 bg-transparent rounded">
			<div class="stat">
				<div class="stat-title">Total CPU</div>
				<div class="stat-value text-2xl text-white">
					{usage?.cpu?.count}
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">CPU Usage</div>
				<div class="stat-value text-2xl text-white">
					{usage?.cpu?.usage}<span class="text-sm">%</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Load Average (5,10,30mins)</div>
				<div class="stat-value text-2xl text-white">{usage?.cpu?.load}</div>
			</div>
		</div>
		<div class="stats stats-vertical min-w-[16rem] mb-5 bg-transparent rounded">
			<div class="stat">
				<div class="stat-title">Total Disk</div>
				<div class="stat-value text-2xl text-white">
					{usage?.disk?.totalGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Used Disk</div>
				<div class="stat-value text-2xl text-white">
					{usage?.disk?.usedGb}<span class="text-sm">GB</span>
				</div>
			</div>

			<div class="stat">
				<div class="stat-title">Free Disk</div>
				<div class="stat-value text-2xl text-white">
					{usage?.disk?.freePercentage}<span class="text-sm">%</span>
				</div>
			</div>
		</div>
	</div>
</div>
