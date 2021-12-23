<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		let url = `/applications/${page.params.id}/prmr.json`;
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
		Pull/Merge requests for <a
			href="http://{$appConfiguration.configuration.domain}"
			target="_blank">{$appConfiguration.configuration.domain}</a
		>
	</div>
</div>

<div class="max-w-4xl mx-auto px-6">
	<div class="flex-col justify-start space-y-1">
		{#each containers as container}
			{container.domain}
		{/each}
	</div>
</div>
