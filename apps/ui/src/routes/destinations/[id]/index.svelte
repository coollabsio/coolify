<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let destination: any;
	export let settings: any;
	export let state: any;
	import { page } from '$app/stores';
	import New from './_New.svelte';
	import Destination from './_Destination.svelte';
	const { id } = $page.params;
</script>

{#if id === 'new'}
	<New />
{:else}
	<Destination bind:destination bind:state bind:settings />
{/if}
