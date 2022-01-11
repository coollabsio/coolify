<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { application } = stuff;
		if (application?.gitSourceId && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${params.id}`
			};
		}
		const endpoint = `/sources.json`;
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
	import type Prisma from '@prisma/client';

	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let sources: Prisma.GitSource[];
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Git Source</div>
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
						<button
							disabled={source.gitlabApp && !source.gitlabAppId}
							type="submit"
							class="disabled:opacity-95 bg-coolgray-200 disabled:text-white box-selection hover:bg-orange-700"
							class:border-red-500={source.gitlabApp && !source.gitlabAppId}
							class:border-0={source.gitlabApp && !source.gitlabAppId}
							class:border-l-4={source.gitlabApp && !source.gitlabAppId}
						>
							<div class="font-bold text-xl text-center truncate">{source.name}</div>
							{#if source.gitlabApp && !source.gitlabAppId}
								<div class="font-bold text-center text-xs truncate text-red-500">
									Not configured
								</div>
							{/if}
						</button>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
