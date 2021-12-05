<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		const { application } = stuff;
		if (application?.buildPack && !page.query.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${page.params.id}`
			};
		}
		const url = `/applications/${page.params.name}/configuration/buildpack.json`;
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
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';
	
	const { id } = $page.params;
	const from = $page.query.get('from');

	export let buildPacks: BuildPack[];
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Configure Build Pack</div>
</div>
<div class="flex flex-wrap justify-center">
	{#each buildPacks as buildPack}
		<div class="p-2">
			<form
				action="/applications/{id}/configuration/buildpack.json"
				method="post"
				use:enhance={{
					result: async () => {
						window.location.assign(from || `/applications/${id}`);
					}
				}}
			>
				<input class="hidden" name="buildPack" value={buildPack.name} />
				<button
					type="submit"
					class="box-selection text-xl font-bold"
					class:hover:border-green-500={buildPack.name === 'node'}
					class:hover:border-red-500={buildPack.name === 'static'}>{buildPack.name}</button
				>
			</form>
		</div>
	{/each}
</div>
