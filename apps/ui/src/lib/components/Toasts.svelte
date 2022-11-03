<script lang="ts">
	import Toast from './Toast.svelte';

	import { dismissToast, pauseToast, resumeToast, toasts } from '$lib/store';
</script>

{#if $toasts.length > 0}
	<section>
		<article class="toast toast-top toast-center rounded-none w-2/3 lg:w-[20rem]" role="alert">
			{#each $toasts as toast (toast.id)}
				<Toast
					type={toast.type}
					on:resume={() => resumeToast(toast.id)}
					on:pause={() => pauseToast(toast.id)}
					on:click={() => dismissToast(toast.id)}>{@html toast.message}</Toast
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
