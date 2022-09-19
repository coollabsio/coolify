<script lang="ts">
	// import { onMount } from 'svelte';

	// import Tooltip from './Tooltip.svelte';
	export let explanation = '';
	export let position = 'dropdown-right'
	// let id: any;
	// let self: any;
	// onMount(() => {
	// 	id = `info-${self.offsetLeft}-${self.offsetTop}`;
	// });
</script>

<div class={`dropdown dropdown-end ${position}`}>
	<label tabindex="0" class="btn btn-circle btn-ghost btn-xs text-sky-500">
	  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="w-4 h-4 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
	</label>
	<div tabindex="0" class="card compact dropdown-content shadow bg-coolgray-400 rounded w-64">
	  <div class="card-body">
		<!-- <h2 class="card-title">You needed more info?</h2>  -->
		<p class="text-xs font-normal">{@html explanation}</p>
	  </div>
	</div>
  </div>
<!-- <div {id} class="inline-block mx-2 cursor-pointer" bind:this={self}>
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
{/if} -->
