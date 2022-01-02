<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.source) {
			return {
				props: {
					source: stuff.source
				}
			};
		}
		const url = `/sources/${params.id}.json`;
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
	export let source: Prisma.GitSource;
	import type Prisma from '@prisma/client';
	import { page } from '$app/stores';
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';

	const { id } = $page.params;
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl">
	<div class="tracking-tight">Git Source</div>
	<span class="px-1 arrow-right-applications">></span>
	<span class="pr-2">{source.name}</span>
</div>
<div class="flex justify-center space-x-2 px-6">
	{#if source.type === 'github'}
		<Github {source} />
	{:else if source.type === 'gitlab'}
		<Gitlab {source} />
	{/if}
</div>
