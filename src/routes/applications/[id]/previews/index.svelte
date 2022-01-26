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

<div class="flex space-x-1 py-6 px-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Previews for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>

<div class="mx-auto max-w-4xl px-6">
	<div class="flex flex-wrap justify-center space-x-2">
		{#if containers.length > 0}
			{#each containers as container}
				<a href={container.fqdn} class="p-2 no-underline" target="_blank">
					<div class="box-selection text-center hover:border-transparent hover:bg-coolgray-200">
						<div class="truncate text-center text-xl font-bold">{getDomain(container.fqdn)}</div>
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
