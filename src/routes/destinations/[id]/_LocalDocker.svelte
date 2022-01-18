<script lang="ts">
	export let destination;
	export let state;
	import { toast } from '@zerodevx/svelte-toast';
	import { page } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import FoundApp from './_FoundApp.svelte';
	const { id } = $page.params;

	let formEl: HTMLFormElement;
	let payload = {
		name: undefined,
		isSwarm: false,
		engine: undefined,
		network: undefined,
		isCoolifyProxyUsed: false
	};
	let scannedApps = [];
	let loading = false;
	if (destination) {
		payload = {
			name: destination.name,
			isSwarm: destination.isSwarm,
			engine: destination.engine,
			network: destination.network,
			isCoolifyProxyUsed: destination.isCoolifyProxyUsed
		};
	}

	async function submitForm() {
		loading = true;
		const saveForm = new FormData(formEl);
		saveForm.append('isCoolifyProxyUsed', payload.isCoolifyProxyUsed.toString());

		const saveFormResponse = await fetch(`/destinations/${id}.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: saveForm
		});
		if (!saveFormResponse.ok) {
			const err = await saveFormResponse.json();
			return errorNotification(err.message);
		}
		window.location.reload();
	}
	async function scanApps() {
		scannedApps = [];
		const data = await fetch(`/destinations/${id}/scan.json`);
		const { containers } = await data.json();
		scannedApps = containers;
	}
	async function changeProxySetting() {
		if (payload.isCoolifyProxyUsed === true) {
			const sure = confirm(
				`Are you sure you want to ${
					payload.isCoolifyProxyUsed ? 'disable' : 'enable'
				} Coolify proxy? It will remove the proxy for all configured networks and all deployments on '${
					payload.engine
				}'! Nothing will be reachable if you do it!`
			);
			if (sure) {
				payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed;
				const saveForm = new FormData(formEl);
				saveForm.append('isCoolifyProxyUsed', payload.isCoolifyProxyUsed.toString());
				saveForm.append('engine', payload.engine);

				const saveFormResponse = await fetch(`/destinations/${id}/settings.json`, {
					method: 'POST',
					headers: {
						accept: 'application/json'
					},
					body: saveForm
				});
				if (!saveFormResponse.ok) {
					const err = await saveFormResponse.json();
					return errorNotification(err.message);
				}
				await stopProxy();
			}
		} else {
			payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed;
			const saveForm = new FormData(formEl);
			saveForm.append('isCoolifyProxyUsed', payload.isCoolifyProxyUsed.toString());
			saveForm.append('engine', payload.engine);

			const saveFormResponse = await fetch(`/destinations/${id}/settings.json`, {
				method: 'POST',
				headers: {
					accept: 'application/json'
				},
				body: saveForm
			});
			if (!saveFormResponse.ok) {
				const err = await saveFormResponse.json();
				return errorNotification(err.message);
			}
			await startProxy()
		}
	}
	async function stopProxy() {
		const saveForm = new FormData(formEl);
		saveForm.append('engine', payload.engine);

		const saveFormResponse = await fetch(`/destinations/${id}/stop.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: saveForm
		});
		if (!saveFormResponse.ok) {
			const err = await saveFormResponse.json();
			return errorNotification(err.message);
		}
		toast.push('Coolify Proxy stopped!');
	}
	async function startProxy() {
		const saveForm = new FormData(formEl);
		saveForm.append('engine', payload.engine);

		const saveFormResponse = await fetch(`/destinations/${id}/start.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: saveForm
		});
		if (!saveFormResponse.ok) {
			const err = await saveFormResponse.json();
			return errorNotification(err.message);
		}
		toast.push('Coolify Proxy started!');
	}
</script>

<div class="flex justify-center pb-8 px-6">
	<form
		on:submit|preventDefault={submitForm}
		bind:this={formEl}
		method="post"
		class="grid grid-flow-row gap-2 py-4"
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button
				type="submit"
				class="bg-sky-600 hover:bg-sky-500"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading ? 'Saving...' : 'Save'}
			</button>
			<button type="button" class="bg-coollabs hover:bg-coollabs-100" on:click={scanApps}
				>Scan for applications</button
			>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input name="name" placeholder="name" bind:value={payload.name} />
			</div>
		</div>
		<!-- <div class="flex items-center">
			<label for="isSwarm">Is it a Docker Swarm?</label>
			<div class="text-left">
				<input name="isSwarm" type="checkbox" checked={payload.isSwarm} />
			</div>
		</div> -->
		<div class="grid grid-cols-3 items-center">
			<label for="engine">Engine</label>
			<div class="col-span-2">
				<input
					readonly
					name="engine"
					placeholder="/var/run/docker.sock"
					bind:value={payload.engine}
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="network">Network</label>
			<div class="col-span-2">
				<input
					readonly
					name="network"
					placeholder="default: coolify"
					bind:value={payload.network}
				/>
			</div>
		</div>
		<div class="flex justify-start">
			<ul class="mt-2 divide-y divide-stone-800">
				<Setting
					bind:setting={payload.isCoolifyProxyUsed}
					on:click={changeProxySetting}
					isPadding={false}
					title="Use Coolify Proxy?"
					description="This will install a proxy on the destination to allow you to access your applications and services without any manual configuration (recommended for Docker). Databases will have their own proxy."
				/>
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

{#if scannedApps.length > 0}
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
{/if}
