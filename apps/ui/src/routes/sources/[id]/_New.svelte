<script lang="ts">
	export let source: any;
	export let settings: any;
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import GithubIcon from '$lib/components/svg/sources/GithubIcon.svelte';
	import GitlabIcon from '$lib/components/svg/sources/GitlabIcon.svelte';
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';
	function setPredefined(type: string) {
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

<ContextMenu>
	<div class="title">
		New Git Source
	</div>
</ContextMenu>

<div class="flex flex-col justify-center">
	<div class="flex-col space-y-2 pb-10 text-center">
		<div class="text-xl font-bold text-white mb-8">Please, select a git server to connect</div>
		<div class="flex justify-center space-x-2">
			<button class="btn btn-lg" on:click={() => setPredefined('github')}>
				<GithubIcon />
				<div class="ml-4">GitHub</div>
			</button>
			<button class="btn btn-lg" on:click={() => setPredefined('gitlab')}>
				<GitlabIcon />
				<div class="ml-4">GitLab</div>
			</button>
		</div>
	</div>
	{#if source?.type}
		<div>
			{#if source.type === 'github'}
				<Github bind:source {settings} />
			{:else if source.type === 'gitlab'}
				<Gitlab bind:source {settings} />
			{/if}
		</div>
	{/if}
</div>
