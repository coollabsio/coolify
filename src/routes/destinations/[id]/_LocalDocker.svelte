<script lang="ts">
	export let destination;
	export let settings;
	export let state;

	import { toast } from '@zerodevx/svelte-toast';
	import { page, session } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { onMount } from 'svelte';
	const { id } = $page.params;
	let cannotDisable = settings.fqdn && destination.engine === '/var/run/docker.sock';
	let loading = false;
	let loadingProxy = false;
	let restarting = false;
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
				loadingProxy = true;
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
			} finally {
				loadingProxy = false;
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
		const sure = confirm(
			'Are you sure you want to restart the proxy? Everything will be reconfigured in ~10 secs.'
		);
		if (sure) {
			try {
				restarting = true;
				toast.push('Coolify Proxy restarting...');
				await post(`/destinations/${id}/restart.json`, {
					engine: destination.engine,
					fqdn: settings.fqdn
				});
			} catch ({ error }) {
				setTimeout(() => {
					window.location.reload();
				}, 5000);
			} finally {
				restarting = false;
			}
		}
	}
</script>

<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
	<div class="flex space-x-1 pb-5">
		<div class="title font-bold">Configuration</div>
		{#if $session.isAdmin}
			<button
				type="submit"
				class="bg-sky-600 hover:bg-sky-500"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading ? 'Saving...' : 'Save'}
			</button>
			<button
				class={restarting ? '' : 'bg-red-600 hover:bg-red-500'}
				disabled={restarting}
				on:click|preventDefault={forceRestartProxy}
				>{restarting ? 'Restarting... please wait...' : 'Force restart proxy'}</button
			>
		{/if}
		<!-- <button type="button" class="bg-coollabs hover:bg-coollabs-100" on:click={scanApps}
				>Scan for applications</button
			> -->
	</div>
	<div class="grid grid-cols-2 items-center px-10 ">
		<label for="name" class="text-base font-bold text-stone-100">Name</label>
		<input
			name="name"
			placeholder="name"
			disabled={!$session.isAdmin}
			readonly={!$session.isAdmin}
			bind:value={destination.name}
		/>
	</div>

	<div class="grid grid-cols-2 items-center px-10">
		<label for="engine" class="text-base font-bold text-stone-100">Engine</label>
		<CopyPasswordField
			id="engine"
			readonly
			disabled
			name="engine"
			placeholder="eg: /var/run/docker.sock"
			value={destination.engine}
		/>
	</div>
	<!-- <div class="flex items-center">
			<label for="remoteEngine">Remote Engine?</label>
			<input name="remoteEngine" type="checkbox" bind:checked={payload.remoteEngine} />
		</div> -->
	<div class="grid grid-cols-2 items-center px-10">
		<label for="network" class="text-base font-bold text-stone-100">Network</label>
		<CopyPasswordField
			id="network"
			readonly
			disabled
			name="network"
			placeholder="default: coolify"
			value={destination.network}
		/>
	</div>
	{#if $session.teamId === '0'}
		<div class="grid grid-cols-2 items-center">
			<Setting
				loading={loadingProxy}
				disabled={cannotDisable}
				bind:setting={destination.isCoolifyProxyUsed}
				on:click={changeProxySetting}
				title="Use Coolify Proxy?"
				description={`This will install a proxy on the destination to allow you to access your applications and services without any manual configuration. Databases will have their own proxy. <br><br>${
					cannotDisable
						? '<span class="font-bold text-white">You cannot disable this proxy as FQDN is configured for Coolify.</span>'
						: ''
				}`}
			/>
		</div>
	{/if}
</form>
