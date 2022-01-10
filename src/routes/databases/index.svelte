<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/databases.json`;
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
	export let databases;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Databases</div>
	<a href="/new/database" class="add-icon bg-purple-600 hover:bg-purple-500">
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

<div class="flex flex-wrap justify-center">
	{#if !databases || databases.length === 0}
		<div class="flex-col">
			<div class="text-center font-bold text-xl">No databases found</div>
		</div>
	{:else}
		{#each databases as database}
			<a href="/databases/{database.id}" class="no-underline p-2">
				<div class="box-selection hover:bg-purple-900">
					<div class="font-bold text-xl text-center truncate">{database.name}</div>
					{#if !database.type}
						<div class="font-bold text-center truncate text-red-500">Not configured</div>
						{:else}
						<div class="text-center truncate">{database.type}</div>
					{/if}
				</div>
			</a>
		{/each}
	{/if}
</div>
