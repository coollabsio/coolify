<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import {loadResources} from '$lib/resources';
	export const load: Load = loadResources;
</script>

<script lang="ts">
	export let gitSources:any;
	import PublicBadge from "$lib/components/badges/PublicBadge.svelte";
	import TeamsBadge from "$lib/components/badges/TeamsBadge.svelte";
	import ContextMenu from "$lib/components/ContextMenu.svelte";
	import Grid3 from "$lib/components/grids/Grid3.svelte";
	import GithubIcon from "$lib/components/svg/sources/GithubIcon.svelte";
	import GitlabIcon from "$lib/components/svg/sources/GitlabIcon.svelte";
</script>


<ContextMenu>
	<div class="title">Git Sources</div>
</ContextMenu>


<Grid3>
	{#if gitSources.length > 0}
		{#each gitSources as source}
			<a class="no-underline mb-5" href={`/sources/${source.id}`}>
				<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-sources indicator duration-150">
					<div class="w-full flex flex-row">
						<div class="absolute top-0 left-0 -m-5 flex">
							{#if source?.type === 'gitlab'}
								<GitlabIcon />
							{:else if source?.type === 'github'}
								<GithubIcon/>
							{/if}

							{#if source.isSystemWide}
								<PublicBadge/>
							{/if}
						</div>
						<div class="w-full flex flex-col">
							<div class="h-10">
								<h1 class="font-bold text-base truncate">{source.name}</h1>
								<TeamsBadge teams={source.teams} thing={source}/>
							</div>

							<div class="flex justify-end items-end space-x-2 h-10" />
						</div>
					</div>
				</div>
			</a>
		{/each}
	{:else}
		<h1 class="">Nothing here yet!</h1>
	{/if}
</Grid3>

