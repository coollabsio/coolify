<script lang="ts">
	import { fade } from 'svelte/transition';
	import Toast from './Toast.svelte';

	import { pauseToast, resumeToast, toasts } from '$lib/store';
</script>

{#if $toasts}
	<section>
		<article class="toast toast-top toast-end rounded-none" role="alert" transition:fade>
			{#each $toasts as toast (toast.id)}
				<Toast
					type={toast.type}
					on:resume={() => resumeToast(toast.id)}
					on:pause={() => pauseToast(toast.id)}>{@html toast.message}</Toast
				>
			{/each}
		</article>
	</section>
{/if}

<style lang="postcss">
	section {
		@apply fixed top-0 left-0 right-0 w-full flex flex-col mt-4 justify-center z-[1000];
	}
</style>
