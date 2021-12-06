<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = '/applications.json';
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
	export let applications: Array<Applications>;
	import { session } from '$app/stores';
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Applications</div>
	{#if $session.isAdmin}
		<a href="/new/application" class="add-icon bg-green-600 hover:bg-green-500">
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
<div class="flex flex-wrap justify-center">
	{#if !applications || applications.length === 0}
		<div class="flex-col">
			<div class="text-center font-bold text-xl">No applications found</div>
		</div>
	{:else}
		{#each applications as application}
			<a href="/applications/{application.id}" class="no-underline p-2 ">
				<div
					class="box-selection"
					class:border-red-500={!application.domain ||
						!application.gitSourceId ||
						application.buildPack === 'static'}
					class:border-0={!application.domain ||
						!application.gitSourceId ||
						!application.destinationDockerId}
					class:border-l-4={!application.domain ||
						!application.gitSourceId ||
						!application.destinationDockerId}
					class:border-green-500={application.buildPack === 'node'}
				>
					<div class="font-bold text-xl text-center truncate">{application.name}</div>
					{#if application.domain}
						<div class="text-center truncate">{application.domain}</div>
					{/if}
					{#if !application.gitSourceId || !application.destinationDockerId}
						<div class="font-bold text-xs text-center truncate text-red-500">
							Invalid configuration
						</div>
					{/if}
				</div>
			</a>
		{/each}
	{/if}
</div>
