<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { database } = stuff;
		if (database?.destinationDockerId && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/database/${params.id}`
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

	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let destinations: Prisma.DestinationDocker[];
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Configure Destination</div>
</div>
<div class="flex justify-center">
	{#if !destinations || destinations.length === 0}
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
		<div class="flex flex-wrap justify-center">
			{#each destinations as destination}
				<div class="p-2">
					<form
						action="/databases/{id}/configuration/destination.json"
						method="post"
						use:enhance={{
							result: async () => {
								window.location.assign(from || `/databases/${id}`);
							}
						}}
					>
						<input class="hidden" name="destinationId" value={destination.id} />
						<button type="submit" class="box-selection border-sky-500 font-bold">
							<div class="font-bold text-xl text-center truncate">{destination.name}</div>
							<div class="text-center truncate">{destination.network}</div>
						</button>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
