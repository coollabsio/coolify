<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ page, stuff }) => {
		const { application, githubToken, gitlabToken } = stuff;
		if (application?.branch && application?.repository && !page.query.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${page.params.id}`
			};
		}
		return {
			props: {
				githubToken,
				gitlabToken,
				application
			}
		};
	};
</script>

<script lang="ts">
	export let application;
	export let githubToken;
	export let gitlabToken;
	import GithubRepositories from './_GithubRepositories.svelte';
	import GitlabRepositories from './_GitlabRepositories.svelte';
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Configure Repository / Branch</div>
</div>
<div class="flex flex-wrap justify-center">
	{#if application.gitSource.type === 'github'}
		<GithubRepositories {application} {githubToken} />
	{:else if application.gitSource.type === 'gitlab'}
		<GitlabRepositories {application} {gitlabToken} />
	{/if}
</div>
