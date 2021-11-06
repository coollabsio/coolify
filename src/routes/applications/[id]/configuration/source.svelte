<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		const { application } = stuff;
		if (application?.gitSourceId && !page.query.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${page.params.id}`
			};
		}
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
	import type Prisma from '@prisma/client';

	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.query.get('from');

	export let sources: Prisma.GitSource[];
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Configure Git Source</div>
</div>
<div class="flex justify-center">
	{#if !sources || sources.length === 0}
		<div class="flex-col">
			<div class="pb-2">No configurable Git Source found</div>
			<div class="flex justify-center">
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
			</div>
		</div>
	{:else}
		<div class="flex flex-wrap justify-center">
			{#each sources as source}
				<div class="p-2">
					<form
						action="/applications/{id}/configuration/source.json"
						method="post"
						use:enhance={{
							result: async () => {
								window.location.assign(from || `/applications/${id}/configuration/destination`);
							}
						}}
					>
						<input class="hidden" name="gitSourceId" value={source.id} />
						<button type="submit" class="box-selection border-orange-500 text-xl"
							>{source.name}</button
						>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
