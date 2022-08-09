<script lang="ts">
	export let source: any;
	export let settings: any;
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

<div class="flex h-20 items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-1 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			New Git Source
		</div>
	</div>
</div>
<div class="flex flex-col justify-center">
	<div class="flex-col space-y-2 pb-10 text-center">
		<div class="text-xl font-bold text-white">Select a git type</div>
		<div class="flex justify-center space-x-2">
			<button class="btn btn-sm" on:click={() => setPredefined('github')}>GitHub</button>
			<button class="btn btn-sm" on:click={() => setPredefined('gitlab')}>GitLab</button>
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
