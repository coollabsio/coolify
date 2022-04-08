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
	export let settings;
	import type Prisma from '@prisma/client';
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';

	function setPredefined(type) {
		switch (type) {
			case 'github':
				source.name = 'Github.com';
				source.type = 'github';
				source.htmlUrl = 'https://github.com';
				source.apiUrl = 'https://api.github.com';
				source.organization = undefined;

				break;
			case 'gitlab':
				source.name = 'Gitlab.com';
				source.type = 'gitlab';
				source.htmlUrl = 'https://gitlab.com';
				source.apiUrl = 'https://gitlab.com/api';
				source.organization = undefined;

				break;
			case 'bitbucket':
				source.name = 'Bitbucket.com';
				source.type = 'bitbucket';
				source.htmlUrl = 'https://bitbucket.com';
				source.apiUrl = 'https://api.bitbucket.org';
				source.organization = undefined;

				break;
			default:
				break;
		}
	}
</script>

<div class="flex space-x-1 p-6 px-6 text-2xl font-bold">
	<div class="tracking-tight">Git Source</div>
	<span class="arrow-right-applications px-1 text-orange-500">></span>
	<span class="pr-2">{source.name}</span>
</div>

<div class="flex flex-col justify-center">
	{#if !source.gitlabAppId && !source.githubAppId}
		<div class="flex-col space-y-2 pb-10 text-center">
			<div class="text-xl font-bold text-white">Select a provider</div>
			<div class="flex justify-center space-x-2">
				<button class="w-32" on:click={() => setPredefined('github')}>GitHub.com</button>
				<button class="w-32" on:click={() => setPredefined('gitlab')}>GitLab.com</button>
			</div>
		</div>
	{/if}
	<div>
		{#if source.type === 'github'}
			<Github bind:source />
		{:else if source.type === 'gitlab'}
			<Gitlab bind:source {settings} />
		{/if}
	</div>
</div>
