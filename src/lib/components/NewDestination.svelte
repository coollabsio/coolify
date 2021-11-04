<script lang="ts">
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

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
</script>

<div class="flex justify-center pb-8">
	<form
		{action}
		method="post"
		class="grid grid-flow-row gap-2 py-4"
		use:enhance={{
			result: async (res) => {
				if (!update) {
					const { id } = await res.json();
					window.location.assign(`/destinations/${id}`);
				}
			}
		}}
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button type="submit" class="bg-sky-600 hover:bg-sky-500">Save</button>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input name="name" placeholder="name" bind:value={payload.name} />
			</div>
		</div>
		<div class="flex items-center">
			<label for="isSwarm">Is it a Docker Swarm?</label>
			<div class="text-left">
				<input name="isSwarm" type="checkbox" checked={payload.isSwarm} />
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="engine">Engine</label>
			<div class="col-span-2">
				<input name="engine" placeholder="/var/run/docker.sock" bind:value={payload.engine} />
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="network">Network</label>
			<div class="col-span-2">
				<input name="network" placeholder="default: coollabs" bind:value={payload.network} />
			</div>
		</div>
	</form>
</div>
