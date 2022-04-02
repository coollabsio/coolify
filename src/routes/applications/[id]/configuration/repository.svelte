<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, url, stuff }) => {
		const { application, appId } = stuff;
		if (application?.branch && application?.repository && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${params.id}`
			};
		}
		return {
			props: {
				application,
				appId
			}
		};
	};
</script>

<script lang="ts">
	export let application;
	export let appId;

	import GithubRepositories from './_GithubRepositories.svelte';
	import GitlabRepositories from './_GitlabRepositories.svelte';
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Select a Repository / Project</div>
</div>
<div class="flex flex-wrap justify-center">
	{#if application.gitSource.type === 'github'}
		<GithubRepositories {application} />
	{:else if application.gitSource.type === 'gitlab'}
		<GitlabRepositories {application} {appId} />
	{/if}
</div>
