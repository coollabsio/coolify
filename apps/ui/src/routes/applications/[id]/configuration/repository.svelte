<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, url, stuff }) => {
		try {
			const { application, appId, settings } = stuff;
			if (application?.branch && application?.repository && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/applications/${params.id}`
				};
			}
			return {
				props: {
					application,
					appId,
					settings
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
	import { t } from '$lib/translations';

	export let application: any;
	export let appId: string;
	export let settings: any;

	import GithubRepositories from './_GithubRepositories.svelte';
	import GitlabRepositories from './_GitlabRepositories.svelte';
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		{$t('application.configuration.select_a_repository_project')}
	</div>
</div>
<div class="flex flex-wrap justify-center">
	{#if application.gitSource.type === 'github'}
		<GithubRepositories {application} />
	{:else if application.gitSource.type === 'gitlab'}
		<GitlabRepositories {application} {appId} {settings} />
	{/if}
</div>

