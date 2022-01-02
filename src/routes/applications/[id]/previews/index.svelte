<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		let url = `/applications/${params.id}/previews.json`;
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
	import { appConfiguration } from '$lib/store';
	export let containers;
</script>

<div class="font-bold flex space-x-1 py-6 px-6">
	<div class="text-2xl tracking-tight mr-4">
		Previews for <a
			href="http://{$appConfiguration.configuration.domain}"
			target="_blank">{$appConfiguration.configuration.domain}</a
		>
	</div>
</div>

<div class="max-w-4xl mx-auto px-6">
	<div class="flex flex-wrap justify-center space-x-2">
		{#if containers.length > 0}
		{#each containers as container}
			<a href="http://{container.domain}" class="no-underline p-2" target="_blank">
				<div class="box-selection hover:bg-coolgray-200 hover:border-transparent text-center">
					<div class="font-bold text-xl text-center truncate">{container.domain}</div>
				</div>
			</a>
		{/each}
		{:else}
		<div class="flex-col">
			<div class="text-center font-bold text-xl">No previews available</div>
		</div>
		
		{/if}
		
	</div>
</div>
