<script lang="ts">
import { goto } from '$app/navigation';

	export let payload;

	import { page } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { enhance, errorNotification } from '$lib/form';

	let formEl: HTMLFormElement;
	let loading = false;

	async function submitForm() {
		const networkCheckForm = new FormData();
		networkCheckForm.append('network', payload.network);

		const networkCheckResponse = await fetch(`/new/destination/check.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: networkCheckForm
		});
		if (networkCheckResponse.ok) {
			return errorNotification(
				`A destination with '${payload.network}' network is already configured.`
			);
		}
		const saveForm = new FormData(formEl);
		saveForm.append('isCoolifyProxyUsed', payload.isCoolifyProxyUsed.toString());

		const saveFormResponse = await fetch(`/new/destination/docker.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: saveForm
		});
		if (!saveFormResponse.ok) {
			return errorNotification(await saveFormResponse.json());
		}
		const { id } = await saveFormResponse.json();
		goto(`/destinations/${id}`)
		// window.location.assign(`/destinations/${id}`);
	}
</script>

<div class="flex justify-center pb-8 px-6">
	<form
		on:submit|preventDefault={submitForm}
		bind:this={formEl}
		method="post"
		class="grid grid-flow-row gap-2 py-4"
		use:enhance={{
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button
				type="submit"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading
					? payload.isCoolifyProxyUsed
						? 'Saving and configuring proxy...'
						: 'Saving...'
					: 'Save'}</button
			>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input required name="name" placeholder="name" bind:value={payload.name} />
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
					required
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
					required
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
					on:click={() => (payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed)}
					isPadding={false}
					title="Use Coolify Proxy?"
					description="This will install a proxy on the destination to allow you to access your applications and services without any manual configuration (recommended for Docker). Databases will have their own proxy."
				/>
			</ul>
		</div>
	</form>
</div>
