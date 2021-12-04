<script lang="ts">
	import { page, session } from '$app/stores';
	import { enhance } from '$lib/form';

	let loading = false;
	let payload = {
		name: undefined,
		isSwarm: false,
		engine: undefined,
		network: undefined
	};

	export let destination = payload;
	export let update = false;

	const { id } = $page.params;

	let action = update ? `/destinations/${id}.json` : '/new/destination.json';

	if (destination) {
		payload = {
			name: destination.name,
			isSwarm: destination.isSwarm,
			engine: destination.engine,
			network: destination.network
		};
	}
	function setPredefined(type) {
		switch (type) {
			case 'localdocker':
				payload = {
					name: 'Local Docker',
					isSwarm: false,
					engine: '/var/run/docker.sock',
					network: 'coollabs'
				};
				break;

			default:
				break;
		}
	}
</script>

{#if !update}
	<div class="flex-col text-center space-y-2 pb-10">
		<div class="font-bold text-xl text-white">Predefined destinations</div>
		<div class="flex space-x-2 justify-center">
			<button class="w-32" on:click={() => setPredefined('localdocker')}>Local Docker</button>
		</div>
	</div>
{/if}
<div class="flex justify-center pb-8 px-6">
	<form
		{action}
		method="post"
		class="grid grid-flow-row gap-2 py-4"
		use:enhance={{
			result: async (res) => {
				setTimeout(async () => {
					loading = false;
					if (!update) {
						const { id } = await res.json();
						window.location.assign(`/destinations/${id}`);
					}
				}, 200);
			},
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
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-sky-600={!loading}
					class:hover:bg-sky-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input
					readonly={!$session.isAdmin}
					name="name"
					placeholder="name"
					bind:value={payload.name}
				/>
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
					readonly={!$session.isAdmin}
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
					readonly={!$session.isAdmin}
					name="network"
					placeholder="default: coollabs"
					bind:value={payload.network}
				/>
			</div>
		</div>
	</form>
</div>
