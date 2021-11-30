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
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Applications</div>
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
</div>
<div class="flex flex-wrap justify-center">
	{#each applications as application}
		<a href="/applications/{application.id}" class="no-underline p-2 ">
			<div
				class="box-selection"
				class:border-yellow-500={!application.domain || !application.gitSourceId}
				class:border-2={!application.domain || !application.gitSourceId}
				class:text-black={!application.domain}
				class:hover:border-green-500={application.buildPack === 'node'}
				class:hover:border-red-500={application.buildPack === 'static'}
			>
				{#if !application.gitSourceId}
					<div class="text-center font-bold text-red-500 -mt-5">Git Source not configured!</div>
				{/if}
				<div class="font-bold text-xl text-center pb-4 truncate">{application.name}</div>
				<div class="text-center truncate">{application.domain || 'Not Configured'}</div>
			</div>
		</a>
	{/each}
</div>
