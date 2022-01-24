<script lang="ts">
	import { goto } from '$app/navigation';

	export let payload;

	import { page } from '$app/stores';
	import Explainer from '$lib/components/Explainer.svelte';
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
		goto(`/destinations/${id}`);
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

		<div class="grid grid-cols-3 items-center">
			<label for="engine">Engine</label>
			<div class="col-span-2">
				<input
					required
					name="engine"
					placeholder="eg: /var/run/docker.sock"
					bind:value={payload.engine}
				/>
				<!-- <Explainer text="You can use remote Docker Engine with over SSH." /> -->
			</div>
		</div>
		<!-- <div class="flex items-center">
			<label for="remoteEngine">Remote Docker Engine?</label>
			<input name="remoteEngine" type="checkbox" bind:checked={payload.remoteEngine} />
		</div>
		{#if payload.remoteEngine}
			<div class="grid grid-cols-3 items-center">
				<label for="user">User</label>
				<div class="col-span-2">
					<input required name="user" placeholder="eg: root" bind:value={payload.user} />
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="port">Port</label>
				<div class="col-span-2">
					<input required name="port" placeholder="eg: 22" bind:value={payload.port} />
				</div>
			</div>
		{/if} -->
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
