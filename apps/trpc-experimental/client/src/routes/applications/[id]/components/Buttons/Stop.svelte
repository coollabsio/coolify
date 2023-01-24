<script lang="ts">
	import { createEventDispatcher } from 'svelte';

	import { errorNotification } from '$lib/common';
	import { trpc } from '$lib/store';

	export let id: string;

	const dispatch = createEventDispatcher();

	async function handleSubmit() {
		try {
			dispatch('stopping');
			await trpc.applications.stop.mutate({ id });
			dispatch('stopped');
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<button on:click={handleSubmit} class="btn btn-sm gap-2">
	<svg
		xmlns="http://www.w3.org/2000/svg"
		class="w-6 h-6 text-error"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<rect x="6" y="5" width="4" height="14" rx="1" />
		<rect x="14" y="5" width="4" height="14" rx="1" />
	</svg> Stop
</button>
