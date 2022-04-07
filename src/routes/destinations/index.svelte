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
	{#if !destinations || destinations.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No destination found</div>
		</div>
	{:else}
		<div class="flex flex-col">
			{#if $session.teamId === '0' && ownDestinations.length > 0 && otherDestinations.length > 0}
				<div class="text-xl font-bold pb-5 px-6">Current Team</div>
			{/if}
			<div class="flex flex-col md:flex-row flex-wrap px-2 justify-center">
				{#each ownDestinations as destination}
					<a href="/destinations/{destination.id}" class="no-underline p-2 w-96">
						<div class="box-selection hover:bg-sky-600">
							<div class="font-bold text-xl text-center truncate">{destination.name}</div>
							{#if $session.teamId === '0'}
								<div class="text-center truncate">{destination.teams[0].name}</div>
							{/if}
							<div class="text-center truncate">{destination.network}</div>
						</div>
					</a>
				{/each}
			</div>

			{#if otherDestinations.length > 0 && $session.teamId === '0'}
				<div class="text-xl font-bold pb-5 pt-10 px-6">Others</div>
				<div class="flex flex-col md:flex-row flex-wrap px-2 justify-center">
					{#each otherDestinations as destination}
						<a href="/destinations/{destination.id}" class="no-underline p-2 w-96">
							<div class="box-selection hover:bg-sky-600">
								<div class="font-bold text-xl text-center truncate">{destination.name}</div>
								{#if $session.teamId === '0'}
									<div class="text-center truncate">{destination.teams[0].name}</div>
								{/if}
								<div class="text-center truncate">{destination.network}</div>
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
