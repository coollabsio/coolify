<script lang="ts">
	import { onMount } from 'svelte';

	import Tooltip from './Tooltip.svelte';
	export let explanation = '';
	let id: any;
	let self: any;
	onMount(() => {
		id = `info-${self.offsetLeft}-${self.offsetTop}`;
	});
</script>

<div {id} class="inline-block mx-2 cursor-pointer" bind:this={self}>
	<svg
		fill="none"
		height="14"
		shape-rendering="geometricPrecision"
		stroke="currentColor"
		stroke-linecap="round"
		stroke-linejoin="round"
		stroke-width="1.4"
		viewBox="0 0 24 24"
		width="14"
		><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" /><path
			d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"
		/><circle cx="12" cy="17" r=".5" />
	</svg>
</div>
{#if id}
	<Tooltip triggeredBy={`#${id}`}>{@html explanation}</Tooltip>
{/if}
