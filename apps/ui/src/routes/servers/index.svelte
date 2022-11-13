<script context="module" lang="ts">
	import { get } from '$lib/api';
	import Usage from '$lib/components/Usage.svelte';

	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({}) => {
		try {
			const { servers } = await get('/servers');
			return {
				props: {
					servers
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
	export let servers: any;
	import { appSession } from '$lib/store';
	import { goto } from '$app/navigation';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import LocalDockerIcon from '$lib/components/svg/servers/LocalDockerIcon.svelte';
	import RemoteDockerIcon from '$lib/components/svg/servers/RemoteDockerIcon.svelte';
	if ($appSession.teamId !== '0') {
		goto('/');
	}
</script>

<ContextMenu>
	<h1 class="title">Servers</h1>
</ContextMenu>

<div class="container lg:mx-auto lg:p-0 px-8 p-5">
	{#if servers.length > 0}
		<div class="grid grid-col gap-8 auto-cols-max grid-cols-1  p-4">
			{#each servers as server}
				<div class="no-underline mb-5">
					<div class="w-full rounded bg-coolgray-200 indicator">
						{#if $appSession.teamId === '0'}
							<Usage {server} />
						{/if}
					</div>
				</div>
			{/each}
		</div>
	{:else}
		<h1 class="text-center text-xs">Nothing here.</h1>
	{/if}
</div>





{#if (servers.length > 0 && servers.length > 0) || servers.length > 0}
		<div class="flex items-center mt-10">
			<h1 class="title lg:text-3xl">Destinations</h1>
		</div>
	{/if}
	{#if servers.length > 0 && servers.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4 "
		>
			{#if servers.length > 0}
				{#each servers as destination}
					<a class="no-underline mb-5" href={`/destinations/${destination.id}`}>
						<div
							class="w-full rounded p-5 bg-coolgray-200 hover:bg-destinations indicator duration-150"
						>
							<div class="w-full flex flex-row">
								<div class="absolute top-0 left-0 -m-5 h-10 w-10">
									<LocalDockerIcon/>
									{#if destination.remoteEngine}
										<RemoteDockerIcon/>
									{/if}
								</div>
								<div class="w-full flex flex-col">
									<h1 class="font-bold text-base truncate">{destination.name}</h1>
									<div class="h-10 text-xs">
										{#if $appSession.teamId === '0' && destination.remoteVerified === false && destination.remoteEngine}
											<h2 class="text-red-500">Not verified yet</h2>
										{/if}
										{#if destination.remoteEngine && !destination.sshKeyId}
											<h2 class="text-red-500">SSH key missing</h2>
										{/if}
										<div class="title">PENDING TEAMS!</div>
										#if destination.teams.length > 0 && destination.teams[0]?.name
											<div class="truncate">destination.teams[0]?.name</div>
										/if
									</div>
								</div>
							</div>
						</div>
					</a>
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if servers.length > 0}
		{#if servers.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if servers.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each servers as destination}
				<a class="no-underline mb-5" href={`/destinations/${destination.id}`}>
					<div
						class="w-full rounded p-5 bg-coolgray-200 hover:bg-destinations indicator duration-150"
					>
						<div class="w-full flex flex-row">
							<div class="absolute top-0 left-0 -m-5 h-10 w-10">
								<svg
									xmlns="http://www.w3.org/2000/svg"
									class="absolute top-0 left-0 -m-2 h-12 w-12 text-sky-500"
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
							</div>
							<div class="w-full flex flex-col">
								<h1 class="font-bold text-base truncate">{destination.name}</h1>
								<div class="h-10 text-xs">
									{#if $appSession.teamId === '0' && destination.remoteVerified === false && destination.remoteEngine}
										<h2 class="text-red-500">Not verified yet</h2>
									{/if}
									{#if destination.remoteEngine && !destination.sshKeyId}
										<h2 class="text-red-500">SSH key missing</h2>
									{/if}
									#if destination.teams.length > 0 && destination.teams[0]?.name
										<div class="truncate">destination.teams[0]?.name</div>
									/if
								</div>
							</div>
						</div>
					</div>
				</a>
			{/each}
		</div>
	{/if}