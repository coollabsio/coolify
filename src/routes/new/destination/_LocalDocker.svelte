<script lang="ts">
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';

	export let payload;

	import { post } from '$lib/api';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';

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
		<div class="flex items-center space-x-2 pb-5">
			<div class="title font-bold">Configuration</div>
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
		<div class="mt-2 grid grid-cols-2 items-center px-10">
			<label for="name" class="text-base font-bold text-stone-100">Name</label>
			<input required name="name" placeholder="name" bind:value={payload.name} />
		</div>

		<div class="grid grid-cols-2 items-center px-10">
			<label for="engine" class="text-base font-bold text-stone-100">Engine</label>
			<input
				required
				name="engine"
				placeholder="eg: /var/run/docker.sock"
				bind:value={payload.engine}
			/>
		</div>
		<div class="grid grid-cols-2 items-center px-10">
			<label for="network" class="text-base font-bold text-stone-100">Network</label>
			<input required name="network" placeholder="default: coolify" bind:value={payload.network} />
		</div>
		{#if $session.teamId === '0'}
			<div class="grid grid-cols-2 items-center">
				<Setting
					bind:setting={payload.isCoolifyProxyUsed}
					on:click={() => (payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed)}
					title="Use Coolify Proxy?"
					description="This will install a proxy on the destination to allow you to access your applications and services without any manual configuration (recommended for Docker).<br><br>Databases will have their own proxy."
				/>
			</div>
		{/if}
	</form>
</div>
