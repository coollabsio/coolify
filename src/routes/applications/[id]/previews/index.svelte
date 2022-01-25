<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/applications/${params.id}/previews.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					application: stuff.application,
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	export let containers;
	export let application;

	import { getDomain } from '$lib/components/common';
</script>

<div class="font-bold flex space-x-1 py-6 px-6">
	<div class="text-2xl tracking-tight mr-4">
		Previews for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>

<div class="max-w-4xl mx-auto px-6">
	<div class="flex flex-wrap justify-center space-x-2">
		{#if containers.length > 0}
			{#each containers as container}
				<a href={container.fqdn} class="no-underline p-2" target="_blank">
					<div class="box-selection hover:bg-coolgray-200 hover:border-transparent text-center">
						<div class="font-bold text-xl text-center truncate">{getDomain(container.fqdn)}</div>
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
