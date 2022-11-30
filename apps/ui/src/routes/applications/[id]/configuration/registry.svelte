<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { application } = stuff;
			if (application?.destinationDockerId && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/applications/${params.id}`
				};
			}
			const response = await get(`/settings`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let registries: any;
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	async function handleSubmit(registryId: any) {
		try {
			await post(`/applications/${id}/configuration/registry`, { registryId });
			return await goto(from || `/applications/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex flex-col justify-center w-full">
	<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row mx-auto gap-4">
		{#if registries.length > 0}
			{#each registries as registry}
				<button
					on:click={() => handleSubmit(registry.id)}
					class="box-selection hover:bg-primary relative"
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="absolute top-0 left-0 -m-4 h-12 w-12 text-sky-500"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
						/>
						<path d="M5 10h3v3h-3z" />
						<path d="M8 10h3v3h-3z" />
						<path d="M11 10h3v3h-3z" />
						<path d="M8 7h3v3h-3z" />
						<path d="M11 7h3v3h-3z" />
						<path d="M11 4h3v3h-3z" />
						<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
						<line x1="10" y1="16" x2="10" y2="16.01" />
					</svg>

					<div class="font-bold text-xl text-center truncate">{registry.name}</div>
					<div class="text-center truncate">{registry.url}</div>
				</button>
			{/each}
		{:else}
		<div class="flex flex-col items-center gap-2">
			<div class="text-center text-xl font-bold pb-4">No registries found.</div>
			<div class="flex gap-2">
			<a class="btn btn-sm" href={from || `/applications/${id}`}>Go back</a>
			<a class="btn btn-sm btn-primary" href={`/settings/docker`}>Add a Docker Registry</a>
		</div>
		</div>
		{/if}
	</div>
</div>
