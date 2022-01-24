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

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Git Source</div>
</div>

<div class="flex-col text-center space-y-2 pb-10">
	<div class="font-bold text-xl text-white">Offical providers</div>
	<div class="flex space-x-2 justify-center">
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
