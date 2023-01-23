<script lang="ts">
	import type { LayoutData } from './$types';

	export let data: LayoutData;
	let destination = data.destination.destination;
	let settings = data.destination.settings;

	import { page } from '$app/stores';
	import New from './components/New.svelte';
	import Destination from './components/Destination.svelte';
	const { id } = $page.params;
</script>

{#if id === 'new'}
	<New />
{:else}
	<Destination bind:destination bind:settings />
{/if}
