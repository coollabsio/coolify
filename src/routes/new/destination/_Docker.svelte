<script lang="ts">
	import { goto } from '$app/navigation';

	export let payload;

	import { post } from '$lib/api';
	import Setting from '$lib/components/Setting.svelte';
	import { enhance, errorNotification } from '$lib/form';

	let loading = false;

	async function handleSubmit() {
		try {
			await post('/new/destination/check.json', { network: payload.network });
			const { id } = await post('/new/destination/docker.json', {
				...payload
			});
			return await goto(`/destinations/${id}`);
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
