<script lang="ts">
	import ContextMenu from '$lib/components/ContextMenu.svelte';
import GithubIcon from '$lib/components/svg/sources/GithubIcon.svelte';
	import GitlabIcon from '$lib/components/svg/sources/GitlabIcon.svelte';
	import Github from './_Github.svelte';
	import Gitlab from './_Gitlab.svelte';

	export let source: any;
	export let settings: any;
	// 		<span class="text-xs">{source.name}</span>
</script>

<ContextMenu>
	<div>
		<div class="title flex flex-row">
			{#if source?.type === 'gitlab'}
				<GitlabIcon />
			{:else if source?.type === 'github'}
				<GithubIcon/>
			{/if}
			<span class="ml-4">Configuration</span>
		</div>
	</div>
</ContextMenu>

{#if source.type === 'github'}
	<Github bind:source {settings} />
{:else if source.type === 'gitlab'}
	<Gitlab bind:source {settings} />
{/if}