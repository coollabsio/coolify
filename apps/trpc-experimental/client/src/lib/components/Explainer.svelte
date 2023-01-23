<script lang="ts">
	// import { onMount } from 'svelte';

	// import Tooltip from './Tooltip.svelte';
	export let explanation = '';
	export let position = 'dropdown-right';
	// let id: any;
	// let self: any;
	// onMount(() => {
	// 	id = `info-${self.offsetLeft}-${self.offsetTop}`;
	// });
</script>

<div class={`dropdown dropdown-end ${position}`}>
	<!-- svelte-ignore a11y-label-has-associated-control -->
	<!-- svelte-ignore a11y-no-noninteractive-tabindex -->
	<label tabindex="0" class="btn btn-circle btn-ghost btn-xs text-sky-500">
		<svg
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			class="w-4 h-4 stroke-current"
			><path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
			/></svg
		>
	</label>
	<!-- svelte-ignore a11y-no-noninteractive-tabindex -->
	<div tabindex="0" class="card compact dropdown-content shadow bg-coolgray-400 rounded w-64">
		<div class="card-body">
			<!-- <h2 class="card-title">You needed more info?</h2>  -->
			<p class="text-xs font-normal">{@html explanation}</p>
		</div>
	</div>
</div>
