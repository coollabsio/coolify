<script context="module" lang="ts">
	import { get } from '$lib/api';
	import Usage from '$lib/components/Usage.svelte';

	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({}) => {
		try {
			const {servers} = await get('/servers');
			const {destinations} = await get('/resources');
			return {
				props: { servers, destinations }
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
	export let destinations:any;
	import { appSession } from '$lib/store';
	import { goto } from '$app/navigation';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import LocalDockerIcon from '$lib/components/svg/servers/LocalDockerIcon.svelte';
	import RemoteDockerIcon from '$lib/components/svg/servers/RemoteDockerIcon.svelte';
	import PublicBadge from '$lib/components/badges/PublicBadge.svelte';
	import TeamsBadge from '$lib/components/badges/TeamsBadge.svelte';

	import Grid3 from '$lib/components/grids/Grid3.svelte';
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

<ContextMenu>
	<h1 class="title lg:text-3xl">Destinations</h1>
</ContextMenu>

{#if destinations.length > 0}
	<Grid3>
		{#if destinations.length > 0}
			{#each destinations as destination}
				<a class="no-underline mb-5" href={`/destinations/${destination.id}`}>
					<div
						class="w-full rounded p-5 bg-coolgray-200 indicator duration-150"
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
									<TeamsBadge teams={destination.teams} thing={destination}/>
								</div>
							</div>
						</div>
					</div>
				</a>
			{/each}
		{:else}
			<h1 class="">Nothing here.</h1>
		{/if}
	</Grid3>
{/if}
