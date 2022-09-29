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
	export let application: any;
	export let appId: string;
	export let settings: any;

	import GithubRepositories from './_GithubRepositories.svelte';
	import GitlabRepositories from './_GitlabRepositories.svelte';
</script>

{#if application.gitSource.type === 'github'}
	<GithubRepositories {application} />
{:else if application.gitSource.type === 'gitlab'}
	<GitlabRepositories {application} {appId} {settings} />
{/if}
