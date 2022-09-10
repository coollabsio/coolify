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

<nav class="header">
	<h1 class="mr-4 text-2xl font-bold">{$t('index.destinations')}</h1>
	{#if $appSession.isAdmin}
		<a href="/destinations/new" class="btn btn-square btn-sm bg-destinations">
			<svg
				class="h-6 w-6"
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
</nav>
<br />
<div class="flex-col justify-center mt-10 pb-12 sm:pb-16 lg:pt-16">
	{#if !destinations || ownDestinations.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('destination.no_destination_found')}</div>
		</div>
	{/if}
	{#if ownDestinations.length > 0 || otherDestinations.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownDestinations as destination}
					<a href="/destinations/{destination.id}" class="p-2 no-underline relative">
						<div class="box-selection hover:bg-sky-600 ">
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
									class="absolute top-0 left-9 -m-4 h-6 w-6 text-sky-500 rotate-45"
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
							<div class="truncate text-center text-xl font-bold">{destination.name}</div>
							{#if $appSession.teamId === '0' && otherDestinations.length > 0}
								<div class="truncate text-center">{destination.teams[0].name}</div>
							{/if}
							<div class="truncate text-center">{destination.network}</div>
							{#if $appSession.teamId === '0' && destination.remoteVerified === false && destination.remoteEngine}
								<div class="truncate text-center text-sm text-red-500">Not verified yet</div>
							{/if}
							{#if destination.remoteEngine && !destination.sshKeyId}
								<div class="truncate text-center text-sm text-red-500">SSH Key missing!</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>

			{#if otherDestinations.length > 0 && $appSession.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-2xl font-bold text-center">Other Destinations</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherDestinations as destination}
						<a href="/destinations/{destination.id}" class="p-2 no-underline relative">
							<div class="box-selection hover:bg-sky-600">
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
										class="absolute top-0 left-9 -m-4 h-6 w-6 text-sky-500 rotate-45"
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
