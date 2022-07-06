<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let source: any;
	export let settings: any;
	import { page } from '$app/stores';
	import Source from './_Source.svelte';
	import New from './_New.svelte';
	const { id } = $page.params;
</script>


{#if id === 'new'}
	<New bind:source bind:settings />
{:else}
	<Source bind:source bind:settings />
{/if}
