<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { application } = stuff;
			if (application?.destinationDockerId && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/applications/${params.id}`
				};
			}
			const response = await get(`/destinations?onlyVerified=true`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { appSession } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { onMount } from 'svelte';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let destinations: any;

	const ownDestinations = destinations.filter((destination: any) => {
		if (destination.teams[0].id === $appSession.teamId) {
			return destination;
		}
	});
	const otherDestinations = destinations.filter((destination: any) => {
		if (destination.teams[0].id !== $appSession.teamId) {
			return destination;
		}
	});
	async function handleSubmit(destinationId: any) {
		try {
			await post(`/applications/${id}/configuration/destination`, { destinationId });
			return await goto(from || `/applications/${id}/configuration/buildpack`);
		} catch (error) {
			return errorNotification(error);
		}
	}
	onMount(async () => {
		if (destinations.length === 1) {
			await handleSubmit(destinations[0].id);
		}
	});
</script>

<div class="flex flex-col justify-center w-full">
	{#if !destinations || ownDestinations.length === 0}
		<div class="flex-col">
			<div class="pb-2 text-center font-bold">
				{$t('application.configuration.no_configurable_destination')}
			</div>
			<div class="flex justify-center">
				<a
					href={`/destinations/new?from=/applications/${id}/configuration/destination`}
					class="add-icon bg-sky-600 hover:bg-sky-500"
				>
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
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row mx-auto gap-4">
			{#each ownDestinations as destination}
				<button
					on:click={() => handleSubmit(destination.id)}
					class="box-selection hover:bg-primary font-bold relative"
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="absolute top-0 left-0 -m-4 h-12 w-12 text-sky-500"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
						/>
						<path d="M5 10h3v3h-3z" />
						<path d="M8 10h3v3h-3z" />
						<path d="M11 10h3v3h-3z" />
						<path d="M8 7h3v3h-3z" />
						<path d="M11 7h3v3h-3z" />
						<path d="M11 4h3v3h-3z" />
						<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
						<line x1="10" y1="16" x2="10" y2="16.01" />
					</svg>
					{#if destination.remoteEngine}
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="absolute top-0 left-9 -m-2 h-6 w-6 text-sky-500 rotate-45"
							viewBox="0 0 24 24"
							stroke-width="3"
							stroke="currentColor"
							fill="none"
							stroke-linecap="round"
							stroke-linejoin="round"
						>
							<path stroke="none" d="M0 0h24v24H0z" fill="none" />
							<line x1="12" y1="18" x2="12.01" y2="18" />
							<path d="M9.172 15.172a4 4 0 0 1 5.656 0" />
							<path d="M6.343 12.343a8 8 0 0 1 11.314 0" />
							<path d="M3.515 9.515c4.686 -4.687 12.284 -4.687 17 0" />
						</svg>
					{/if}
					<div class="font-bold text-xl text-center truncate">{destination.name}</div>
					<div class="text-center truncate">{destination.network}</div>
				</button>
			{/each}
		</div>
		{#if otherDestinations.length > 0 && $appSession.teamId === '0'}
			<div class="px-6 pb-5 pt-10 title">Other Destinations</div>
		{/if}
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row mx-auto gap-4">
			{#each otherDestinations as destination}
				<button
					class="box-selection hover:bg-sky-700 font-bold relative"
					on:click={() => handleSubmit(destination.id)}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="absolute top-0 left-0 -m-4 h-12 w-12 text-sky-500"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
						/>
						<path d="M5 10h3v3h-3z" />
						<path d="M8 10h3v3h-3z" />
						<path d="M11 10h3v3h-3z" />
						<path d="M8 7h3v3h-3z" />
						<path d="M11 7h3v3h-3z" />
						<path d="M11 4h3v3h-3z" />
						<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
						<line x1="10" y1="16" x2="10" y2="16.01" />
					</svg>
					{#if destination.remoteEngine}
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="absolute top-0 left-9 -m-2 h-6 w-6 text-sky-500 rotate-45"
							viewBox="0 0 24 24"
							stroke-width="3"
							stroke="currentColor"
							fill="none"
							stroke-linecap="round"
							stroke-linejoin="round"
						>
							<path stroke="none" d="M0 0h24v24H0z" fill="none" />
							<line x1="12" y1="18" x2="12.01" y2="18" />
							<path d="M9.172 15.172a4 4 0 0 1 5.656 0" />
							<path d="M6.343 12.343a8 8 0 0 1 11.314 0" />
							<path d="M3.515 9.515c4.686 -4.687 12.284 -4.687 17 0" />
						</svg>
					{/if}
					<div class="font-bold text-xl text-center truncate">{destination.name}</div>
					<div class="text-center truncate">{destination.network}</div>
				</button>
			{/each}
		</div>
	{/if}
</div>
