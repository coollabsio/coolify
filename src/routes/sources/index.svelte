<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page }) => {
		const url = `/sources.json`;
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
	export let sources;
	import { session } from '$app/stores';
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Git Sources</div>
	{#if $session.permission === 'admin'}
		<a href="/new/source" sveltekit:prefetch class="add-icon bg-orange-600 hover:bg-orange-500">
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
	{#if !sources || sources.length === 0}
		<div class="flex-col">
			<div class="text-center font-bold text-xl">No git sources found</div>
		</div>
	{:else}
		<div class="flex flex-wrap justify-center">
			{#each sources as source}
				<a href="/sources/{source.id}" class="no-underline p-2">
					<div
						class="box-selection"
						class:border-red-500={!source.gitlabAppId}
						class:border-orange-500={source.gitlabAppId}
						class:border-0={!source.gitlabAppId}
						class:border-l-4={!source.gitlabAppId}
					>
						<div class="font-bold text-xl text-center truncate">{source.name}</div>
						{#if !source.gitlabAppId}
							<div class="font-bold text-center text-xs truncate text-red-500">Not configured</div>
						{/if}
					</div>
				</a>
			{/each}
		</div>
	{/if}
</div>
