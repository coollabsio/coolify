<script lang="ts">
	export let destination: any;
	export let settings: any
	export let state: any

	import { toast } from '@zerodevx/svelte-toast';
	import { page, session } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { onMount } from 'svelte';
	import { t } from '$lib/translations';
import { errorNotification, generateRemoteEngine } from '$lib/common';
import { appSession } from '$lib/store';
	const { id } = $page.params;
	let cannotDisable = settings.fqdn && destination.engine === '/var/run/docker.sock';
	// let scannedApps = [];
	let loading = false;
	let restarting = false;
	async function handleSubmit() {
		loading = true;
		try {
			return await post(`/destinations/${id}.json`, { ...destination });
		} catch (error ) {
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
			} catch (error ) {
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
			} catch ( error ) {
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
			const engine = generateRemoteEngine(destination);
			await post(`/destinations/${id}/stop.json`, { engine });
			return toast.push($t('destination.coolify_proxy_stopped'));
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function startProxy() {
		try {
			const engine = generateRemoteEngine(destination);
			await post(`/destinations/${id}/start.json`, { engine });
			return toast.push($t('destination.coolify_proxy_started'));
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function forceRestartProxy() {
		const sure = confirm($t('destination.confirm_restart_proxy'));
		if (sure) {
			try {
				restarting = true;
				toast.push($t('destination.coolify_proxy_restarting'));
				await post(`/destinations/${id}/restart.json`, {
					engine: destination.engine,
					fqdn: settings.fqdn
				});
			} catch ({ error }) {
				setTimeout(() => {
					window.location.reload();
				}, 5000);
			}
		}
	}
</script>

<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
	<div class="flex space-x-1 pb-5">
		<div class="title font-bold">{$t('forms.configuration')}</div>
		{#if $appSession.isAdmin}
			<button
				type="submit"
				class="bg-sky-600 hover:bg-sky-500"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading ? $t('forms.saving') : $t('forms.save')}
			</button>
			<button
				class={restarting ? '' : 'bg-red-600 hover:bg-red-500'}
				disabled={restarting}
				on:click|preventDefault={forceRestartProxy}
				>{restarting
					? $t('destination.restarting_please_wait')
					: $t('destination.force_restart_proxy')}</button
			>
		{/if}
		<!-- <button type="button" class="bg-coollabs hover:bg-coollabs-100" on:click={scanApps}
				>Scan for applications</button
			> -->
	</div>
	<div class="grid grid-cols-2 items-center px-10 ">
		<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
		<input
			name="name"
			placeholder={$t('forms.name')}
			disabled={!$appSession.isAdmin}
			readonly={!$appSession.isAdmin}
			bind:value={destination.name}
		/>
	</div>

	<div class="grid grid-cols-2 items-center px-10">
		<label for="engine" class="text-base font-bold text-stone-100">{$t('forms.engine')}</label>
		<CopyPasswordField
			id="engine"
			readonly
			disabled
			name="engine"
			placeholder="{$t('forms.eg')}: /var/run/docker.sock"
			value={destination.engine}
		/>
	</div>
	<!-- <div class="flex items-center">
			<label for="remoteEngine">Remote Engine?</label>
			<input name="remoteEngine" type="checkbox" bind:checked={payload.remoteEngine} />
		</div> -->
	<div class="grid grid-cols-2 items-center px-10">
		<label for="network" class="text-base font-bold text-stone-100">{$t('forms.network')}</label>
		<CopyPasswordField
			id="network"
			readonly
			disabled
			name="network"
			placeholder="{$t('forms.default')}: coolify"
			value={destination.network}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<Setting
			disabled={cannotDisable}
			bind:setting={destination.isCoolifyProxyUsed}
			on:click={changeProxySetting}
			title={$t('destination.use_coolify_proxy')}
			description={`This will install a proxy on the destination to allow you to access your applications and services without any manual configuration. Databases will have their own proxy. <br><br>${
				cannotDisable
					? '<span class="font-bold text-white">You cannot disable this proxy as FQDN is configured for Coolify.</span>'
					: ''
			}`}
		/>
	</div>
</form>
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
