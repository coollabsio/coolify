<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { tweened } from 'svelte/motion';
	import { cubicOut } from 'svelte/easing';

	let timeout: NodeJS.Timeout | undefined;
	const progress = tweened(0, {
		duration: 2000,
		easing: cubicOut
	});

	onMount(() => {
		timeout = setTimeout(() => {
			progress.set(0.7);
		}, 500);
	});
	onDestroy(() => {
		clearTimeout(timeout);
	});
</script>

<div class="progress-bar">
	<div class="progress-sliver" style={`--width: ${$progress * 100}%`} />
</div>

<style lang="postcss">
	.progress-bar {
		height: 0.2rem;
		@apply fixed top-0 left-0 right-0;
	}
	.progress-sliver {
		width: var(--width);
		@apply h-full bg-coollabs;
	}
</style>
