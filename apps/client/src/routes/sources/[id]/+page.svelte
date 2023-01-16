<script lang="ts">
	import type { LayoutData } from './$types';

	export let data: LayoutData;
	let source = data.source.data.source;
	let settings = data.source.data.settings;
	import { page } from '$app/stores';
	import Source from './components/Source.svelte';
	import New from './components/New.svelte';
	const { id } = $page.params;
</script>

{#if id === 'new'}
	<New bind:source bind:settings />
{:else}
	<Source bind:source bind:settings />
{/if}
