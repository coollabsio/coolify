<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = `/destinations.json`;
		const res = await fetch(url);

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

	import { session } from '$app/stores';
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
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Destinations</div>
	{#if $session.isAdmin}
		<a href="/new/destination" class="add-icon bg-sky-600 hover:bg-sky-500">
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
	{/if}
</div>
<div class="flex justify-center">
	{#if !destinations || ownDestinations.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No destination found</div>
		</div>
	{/if}
	{#if ownDestinations.length > 0 || otherDestinations.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownDestinations as destination}
					<a href="/destinations/{destination.id}" class="w-96 p-2 no-underline">
						<div class="box-selection hover:bg-sky-600">
							<div class="truncate text-center text-xl font-bold">{destination.name}</div>
							{#if $session.teamId === '0' && otherDestinations.length > 0}
								<div class="truncate text-center">{destination.teams[0].name}</div>
							{/if}
							<div class="truncate text-center">{destination.network}</div>
						</div>
					</a>
				{/each}
			</div>

			{#if otherDestinations.length > 0 && $session.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Destinations</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherDestinations as destination}
						<a href="/destinations/{destination.id}" class="w-96 p-2 no-underline">
							<div class="box-selection hover:bg-sky-600">
								<div class="truncate text-center text-xl font-bold">{destination.name}</div>
								{#if $session.teamId === '0'}
									<div class="truncate text-center">{destination.teams[0].name}</div>
								{/if}
								<div class="truncate text-center">{destination.network}</div>
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
