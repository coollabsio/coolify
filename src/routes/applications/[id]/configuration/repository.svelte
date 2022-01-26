<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, url, stuff }) => {
		const { application, githubToken } = stuff;
		if (application?.branch && application?.repository && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${params.id}`
			};
		}
		return {
			props: {
				githubToken,
				application
			}
		};
	};
</script>

<script lang="ts">
	export let application;
	export let githubToken;
	import GithubRepositories from './_GithubRepositories.svelte';
	import GitlabRepositories from './_GitlabRepositories.svelte';
</script>

<div class="flex space-x-1 py-5 px-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Select a Repository / Project</div>
</div>
<div class="flex flex-wrap justify-center">
	{#if application.gitSource.type === 'github'}
		<GithubRepositories {application} {githubToken} />
	{:else if application.gitSource.type === 'gitlab'}
		<GitlabRepositories {application} />
	{/if}
</div>
