<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { application } = stuff;
		if (application?.destinationDockerId && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${params.id}`
			};
		}
		const endpoint = `/destinations.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import type Prisma from '@prisma/client';

	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let destinations: Prisma.DestinationDocker[];

	const ownDestinations = destinations.filter((destination) => {
		if (destination.teams[0].id === $session.teamId) {
			return destination;
		}
	});
	const otherDestinations = destinations.filter((destination) => {
		if (destination.teams[0].id !== $session.teamId) {
			return destination;
		}
	});
	async function handleSubmit(destinationId) {
		try {
			await post(`/applications/${id}/configuration/destination.json`, { destinationId });
			return await goto(from || `/applications/${id}/configuration/buildpack`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Configure Destination</div>
</div>
<div class="flex flex-col justify-center">
	{#if !destinations || ownDestinations.length === 0}
		<div class="flex-col">
			<div class="pb-2">No configurable Destination found</div>
			<div class="flex justify-center">
				<a href="/new/destination" sveltekit:prefetch class="add-icon bg-sky-600 hover:bg-sky-500">
					<svg
						class="w-6"
						xmlns="http://www.w3.org/2000/svg"
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
						><path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M12 6v6m0 0v6m0-6h6m-6 0H6"
						/></svg
					>
				</a>
			</div>
		</div>
	{:else}
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
			{#each ownDestinations as destination}
				<div class="p-2">
					<form on:submit|preventDefault={() => handleSubmit(destination.id)}>
						<button type="submit" class="box-selection hover:bg-sky-700 font-bold">
							<div class="font-bold text-xl text-center truncate">{destination.name}</div>
							<div class="text-center truncate">{destination.network}</div>
						</button>
					</form>
				</div>
			{/each}
		</div>
		{#if otherDestinations.length > 0 && $session.teamId === '0'}
			<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Destinations</div>
		{/if}
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
			{#each otherDestinations as destination}
				<div class="p-2">
					<form on:submit|preventDefault={() => handleSubmit(destination.id)}>
						<button type="submit" class="box-selection hover:bg-sky-700 font-bold">
							<div class="font-bold text-xl text-center truncate">{destination.name}</div>
							<div class="text-center truncate">{destination.network}</div>
						</button>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
