<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/destinations`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let destinations: any[];

	import { t } from '$lib/translations';
	import { appSession } from '$lib/store';
	import { get, post } from '$lib/api';

	const ownDestinations = destinations.filter((destination) => {
		if (destination.teams[0].id === $appSession.teamId) {
			return destination;
		}
	});
	const otherDestinations = destinations.filter((destination) => {
		if (destination.teams[0].id !== $appSession.teamId) {
			return destination;
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.destinations')}</div>
	{#if $appSession.isAdmin}
	    <a href="/destinations/new" class="add-icon bg-sky-600 hover:bg-sky-500">
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
<div class="flex-col justify-center">
	{#if !destinations || ownDestinations.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('destination.no_destination_found')}</div>
		</div>
	{/if}
	{#if ownDestinations.length > 0 || otherDestinations.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownDestinations as destination}
					<a href="/destinations/{destination.id}" class="w-96 p-2 no-underline">
						<div class="box-selection hover:bg-sky-600">
							<div class="truncate text-center text-xl font-bold">{destination.name}</div>
							{#if $appSession.teamId === '0' && otherDestinations.length > 0}
								<div class="truncate text-center">{destination.teams[0].name}</div>
							{/if}
							<div class="truncate text-center">{destination.network}</div>
						</div>
					</a>
				{/each}
			</div>

			{#if otherDestinations.length > 0 && $appSession.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Destinations</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherDestinations as destination}
						<a href="/destinations/{destination.id}" class="w-96 p-2 no-underline">
							<div class="box-selection hover:bg-sky-600">
								<div class="truncate text-center text-xl font-bold">{destination.name}</div>
								{#if $appSession.teamId === '0'}
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
