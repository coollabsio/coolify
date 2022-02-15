<script lang="ts">
	export let destination;
	export let settings;
	export let state;

	import { toast } from '@zerodevx/svelte-toast';
	import { page } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { onMount } from 'svelte';
	const { id } = $page.params;
	let cannotDisable = settings.fqdn && destination.engine === '/var/run/docker.sock';
	// let scannedApps = [];
	let loading = false;
	async function handleSubmit() {
		loading = true;
		try {
			return await post(`/destinations/${id}.json`, { ...destination });
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
	// async function scanApps() {
	// 	scannedApps = [];
	// 	const data = await fetch(`/destinations/${id}/scan.json`);
	// 	const { containers } = await data.json();
	// 	scannedApps = containers;
	// }
	onMount(async () => {
		if (state === false && destination.isCoolifyProxyUsed === true) {
			destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
			try {
				await post(`/destinations/${id}/settings.json`, {
					isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
					engine: destination.engine
				});
				await stopProxy();
			} catch ({ error }) {
				return errorNotification(error);
			}
		} else if (state === true && destination.isCoolifyProxyUsed === false) {
			destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
			try {
				await post(`/destinations/${id}/settings.json`, {
					isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
					engine: destination.engine
				});
				await startProxy();
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	});
	async function changeProxySetting() {
		if (!cannotDisable) {
			const isProxyActivated = destination.isCoolifyProxyUsed;
			if (isProxyActivated) {
				const sure = confirm(
					`Are you sure you want to ${
						destination.isCoolifyProxyUsed ? 'disable' : 'enable'
					} Coolify proxy? It will remove the proxy for all configured networks and all deployments on '${
						destination.engine
					}'! Nothing will be reachable if you do it!`
				);
				if (!sure) return;
			}
			destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
			try {
				await post(`/destinations/${id}/settings.json`, {
					isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
					engine: destination.engine
				});
				if (isProxyActivated) {
					await stopProxy();
				} else {
					await startProxy();
				}
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	}
	async function stopProxy() {
		try {
			await post(`/destinations/${id}/stop.json`, { engine: destination.engine });
			return toast.push('Coolify Proxy stopped!');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function startProxy() {
		try {
			await post(`/destinations/${id}/start.json`, { engine: destination.engine });
			return toast.push('Coolify Proxy started!');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function forceRestartProxy() {
		try {
			toast.push('Coolify Proxy restarting...');
			await post(`/destinations/${id}/stop.json`, { engine: destination.engine });
			await post(`/destinations/${id}/settings.json`, {
				isCoolifyProxyUsed: false,
				engine: destination.engine
			});
			await post(`/destinations/${id}/start.json`, { engine: destination.engine });
			await post(`/destinations/${id}/settings.json`, {
				isCoolifyProxyUsed: true,
				engine: destination.engine
			});
			window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex justify-center px-6 pb-8">
	<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
		<div class="flex h-8 items-center space-x-2">
			<div class="text-xl font-bold text-white">Configuration</div>
			<button
				type="submit"
				class="bg-sky-600 hover:bg-sky-500"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading ? 'Saving...' : 'Save'}
			</button>
			<!-- <button type="button" class="bg-coollabs hover:bg-coollabs-100" on:click={scanApps}
				>Scan for applications</button
			> -->
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input name="name" placeholder="name" bind:value={destination.name} />
			</div>
		</div>

		<div class="grid grid-cols-3 items-center">
			<label for="engine">Engine</label>
			<div class="col-span-2">
				<CopyPasswordField
					id="engine"
					readonly
					disabled
					name="engine"
					placeholder="eg: /var/run/docker.sock"
					value={destination.engine}
				/>
			</div>
		</div>
		<!-- <div class="flex items-center">
			<label for="remoteEngine">Remote Engine?</label>
			<input name="remoteEngine" type="checkbox" bind:checked={payload.remoteEngine} />
		</div> -->
		<div class="grid grid-cols-3 items-center">
			<label for="network">Network</label>
			<div class="col-span-2">
				<CopyPasswordField
					id="network"
					readonly
					disabled
					name="network"
					placeholder="default: coolify"
					value={destination.network}
				/>
			</div>
		</div>
		<div class="flex justify-start">
			<ul class="mt-2 divide-y divide-stone-800">
				<Setting
					disabled={cannotDisable}
					bind:setting={destination.isCoolifyProxyUsed}
					on:click={changeProxySetting}
					isPadding={false}
					title="Use Coolify Proxy?"
					description={`This will install a proxy on the destination to allow you to access your applications and services without any manual configuration. Databases will have their own proxy. <br><br>${
						cannotDisable
							? '<span class="font-bold text-white">You cannot disable this proxy as FQDN is configured for Coolify.</span>'
							: ''
					}`}
				/>
				<button on:click|preventDefault={forceRestartProxy}>Force Restart Proxy</button>
			</ul>
		</div>
	</form>
</div>
<!-- <div class="flex justify-center">
	{#if payload.isCoolifyProxyUsed}
		{#if state}
			<button on:click={stopProxy}>Stop proxy</button>
		{:else}
			<button on:click={startProxy}>Start proxy</button>
		{/if}
	{/if}
</div> -->

<!-- {#if scannedApps.length > 0}
	<div class="flex justify-center px-6 pb-10">
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Found applications</div>
		</div>
	</div>
	<div class="max-w-4xl mx-auto px-6">
		<div class="flex space-x-2 justify-center">
			{#each scannedApps as app}
				<FoundApp {app} />
			{/each}
		</div>
	</div>
{/if} -->
