<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { database } = stuff;
		if (database?.version && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/databases/${params.id}`
			};
		}
		const endpoint = `/databases/${params.id}/configuration/version.json`;
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

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let versions;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Database version</div>
</div>

<div class="flex justify-center">
	{#each versions as version}
		<div class="p-2">
			<form
				action="/databases/{id}/configuration/version.json"
				method="post"
				use:enhance={{
					result: async () => {
						window.location.assign(from || `/databases/${id}`);
					}
				}}
			>
				<input class="hidden" name="version" value={version.version} />
				<button type="submit" class="box-selection text-xl font-bold">{version.name}</button>
			</form>
		</div>
	{/each}
</div>
