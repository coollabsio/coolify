<script lang="ts">
	import { errorNotification } from '$lib/common';
	export let id: string;
	import { trpc } from '$lib/store';

	async function handleSubmit() {
		try {
			return await trpc.applications.restart.mutate({ id });
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<button on:click={handleSubmit} class="btn btn-sm gap-2">
	<svg
		xmlns="http://www.w3.org/2000/svg"
		class="w-6 h-6"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
		<path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
	</svg> Restart
</button>
