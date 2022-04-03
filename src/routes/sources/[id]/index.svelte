<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.source) {
			return {
				props: {
					source: stuff.source,
					settings: stuff.settings
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
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';
	import { t } from '$lib/translations';
</script>

<div class="flex space-x-1 p-6 px-6 text-2xl font-bold">
	<div class="tracking-tight">{$t('application.git_source')}</div>
	<span class="arrow-right-applications px-1 text-orange-500">></span>
	<span class="pr-2">{source.name}</span>
</div>

<div class="flex justify-center px-6 pb-8">
	{#if source.type === 'github'}
		<Github bind:source />
	{:else if source.type === 'gitlab'}
		<Gitlab bind:source />
	{/if}
</div>
