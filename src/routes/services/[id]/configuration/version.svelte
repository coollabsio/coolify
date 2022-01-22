<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { service } = stuff;
		if (service?.version && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/services/${params.id}`
			};
		}
		const endpoint = `/services/${params.id}/configuration/version.json`;
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
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';
	import { goto } from '$app/navigation';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let versions;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Service version</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each versions as version}
		<div class="p-2">
			<form
				action="/services/{id}/configuration/version.json"
				method="post"
				use:enhance={{
					result: async () => {
						goto(from || `/services/${id}`);
					}
				}}
			>
				<input class="hidden" name="version" value={version} />
				<button type="submit" class="box-selection text-xl font-bold">{version}</button>
			</form>
		</div>
	{/each}
</div>
