<script lang="ts">
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';
	let gitSource = {
		name: undefined,
		type: 'github',
		htmlUrl: undefined,
		apiUrl: undefined,
		organization: undefined
	};
	function setPredefined(type) {
		switch (type) {
			case 'github':
				gitSource = {
					name: 'GitHub.com',
					type,
					htmlUrl: 'https://github.com',
					apiUrl: 'https://api.github.com',
					organization: undefined
				};
				break;
			case 'gitlab':
				gitSource = {
					name: 'GitLab.com',
					type,
					htmlUrl: 'https://gitlab.com',
					apiUrl: 'https://gitlab.com/api',
					organization: undefined
				};
				break;
			case 'bitbucket':
				gitSource = {
					name: 'BitBucket.com',
					type,
					htmlUrl: 'https://bitbucket.com',
					apiUrl: 'https://bitbucket.com',
					organization: undefined
				};
				break;
			default:
				break;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Add New Git Source</div>
</div>

<div class="flex-col space-y-2 pb-10 text-center">
	<div class="text-xl font-bold text-white">Offical providers</div>
	<div class="flex justify-center space-x-2">
		<button class="w-32" on:click={() => setPredefined('github')}>GitHub.com</button>
		<button class="w-32" on:click={() => setPredefined('gitlab')}>GitLab.com</button>
		<button class="w-32" on:click={() => setPredefined('bitbucket')}>Bitbucket.com</button>
	</div>
</div>
<div class="px-6">
	{#if gitSource.type === 'github'}
		<Github {gitSource} />
	{:else if gitSource.type === 'gitlab'}
		<Gitlab {gitSource} />
	{:else if gitSource.type === 'bitbucket'}
		<div class="text-center font-bold text-4xl py-10">Not implemented yet</div>
	{/if}
</div>
