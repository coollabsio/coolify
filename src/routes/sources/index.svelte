<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
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
	export let sources;
	import { session } from '$app/stores';
	import { post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { getDomain } from '$lib/components/common';
	const ownSources = sources.filter((source) => {
		if (source.teams[0].id === $session.teamId) {
			return source;
		}
	});
	const otherSources = sources.filter((source) => {
		if (source.teams[0].id !== $session.teamId) {
			return source;
		}
	});
	async function newSource() {
		const { id } = await post('/sources/new', {});
		return await goto(`/sources/${id}`, { replaceState: true });
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Git Sources</div>
	{#if $session.isAdmin}
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
	{/if}
</div>
<div class="flex justify-center">
	{#if !sources || ownSources.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No git sources found</div>
		</div>
	{:else}
		<div class="flex flex-col">
			<div class="flex flex-col md:flex-row flex-wrap px-2 justify-center">
				{#each ownSources as source}
					<a href="/sources/{source.id}" class="no-underline p-2 w-96">
						<div
							class="box-selection hover:bg-orange-600 group"
							class:border-red-500={source.gitlabApp && !source.gitlabAppId}
							class:border-0={source.gitlabApp && !source.gitlabAppId}
							class:border-l-4={source.gitlabApp && !source.gitlabAppId}
						>
							<div class="font-bold text-xl text-center truncate">{source.name}</div>
							{#if $session.teamId === '0' && otherSources.length > 0}
								<div class="text-center truncate">{source.teams[0].name}</div>
							{/if}
							{#if (source.type === 'gitlab' && !source.gitlabAppId) || (source.type === 'github' && !source.githubAppId && !source.githubApp?.installationId)}
								<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{:else}
								<div class="truncate text-center">{getDomain(source.htmlUrl) || ''}</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>

			{#if otherSources.length > 0 && $session.teamId === '0'}
				<div class="text-xl font-bold pb-5 pt-10 px-6">Other Sources</div>
				<div class="flex flex-col md:flex-row flex-wrap px-2 justify-center">
					{#each otherSources as source}
						<a href="/sources/{source.id}" class="no-underline p-2 w-96">
							<div
								class="box-selection hover:bg-orange-600 group"
								class:border-red-500={source.gitlabApp && !source.gitlabAppId}
								class:border-0={source.gitlabApp && !source.gitlabAppId}
								class:border-l-4={source.gitlabApp && !source.gitlabAppId}
							>
								<div class="font-bold text-xl text-center truncate">{source.name}</div>
								{#if $session.teamId === '0'}
									<div class="text-center truncate">{source.teams[0].name}</div>
								{/if}
								{#if (source.type === 'gitlab' && !source.gitlabAppId) || (source.type === 'github' && !source.githubAppId && !source.githubApp?.installationId)}
									<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{:else}
									<div class="truncate text-center">{source.htmlUrl}</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
