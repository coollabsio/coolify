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

	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let sources: Prisma.GitSource[] & {
		gitlabApp: Prisma.GitlabApp;
		githubApp: Prisma.GithubApp;
	};
	const filteredSources = sources.filter(
		(source) =>
			(source.type === 'github' && source.githubAppId && source.githubApp.installationId) ||
			(source.type === 'gitlab' && source.gitlabAppId)
	);
	const ownSources = filteredSources.filter((source) => {
		if (source.teams[0].id === $session.teamId) {
			return source;
		}
	});
	const otherSources = filteredSources.filter((source) => {
		if (source.teams[0].id !== $session.teamId) {
			return source;
		}
	});

	async function handleSubmit(gitSourceId) {
		try {
			await post(`/applications/${id}/configuration/source.json`, { gitSourceId });
			return await goto(from || `/applications/${id}/configuration/repository`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function newSource() {
		const { id } = await post('/sources/new', {});
		return await goto(`/sources/${id}`, { replaceState: true });
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Select a Git Source</div>
</div>
<div class="flex flex-col justify-center">
	{#if !filteredSources || ownSources.length === 0}
		<div class="flex-col">
			<div class="pb-2 text-center">No configurable Git Source found</div>
			<div class="flex justify-center">
				<button on:click={newSource} class="add-icon bg-orange-600 hover:bg-orange-500">
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
				</button>
			</div>
		</div>
	{:else}
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
			{#each ownSources as source}
				<div class="p-2">
					<form on:submit|preventDefault={() => handleSubmit(source.id)}>
						<button
							disabled={source.gitlabApp && !source.gitlabAppId}
							type="submit"
							class="disabled:opacity-95 bg-coolgray-200 disabled:text-white box-selection hover:bg-orange-700 group"
							class:border-red-500={source.gitlabApp && !source.gitlabAppId}
							class:border-0={source.gitlabApp && !source.gitlabAppId}
							class:border-l-4={source.gitlabApp && !source.gitlabAppId}
						>
							<div class="font-bold text-xl text-center truncate">{source.name}</div>
							{#if source.gitlabApp && !source.gitlabAppId}
								<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{/if}
						</button>
					</form>
				</div>
			{/each}
		</div>

		{#if otherSources.length > 0 && $session.teamId === '0'}
			<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Sources</div>
		{/if}
		<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
			{#each otherSources as source}
				<div class="p-2">
					<form on:submit|preventDefault={() => handleSubmit(source.id)}>
						<button
							disabled={source.gitlabApp && !source.gitlabAppId}
							type="submit"
							class="disabled:opacity-95 bg-coolgray-200 disabled:text-white box-selection hover:bg-orange-700 group"
							class:border-red-500={source.gitlabApp && !source.gitlabAppId}
							class:border-0={source.gitlabApp && !source.gitlabAppId}
							class:border-l-4={source.gitlabApp && !source.gitlabAppId}
						>
							<div class="font-bold text-xl text-center truncate">{source.name}</div>
							{#if source.gitlabApp && !source.gitlabAppId}
								<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{/if}
						</button>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
