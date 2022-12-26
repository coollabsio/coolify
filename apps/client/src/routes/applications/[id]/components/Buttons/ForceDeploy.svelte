<script lang="ts">
	import { errorNotification } from '$lib/common';
	export let id: string;
	import { trpc } from '$lib/store';
	async function handleSubmit() {
		try {
			await trpc.applications.forceRedeploy.mutate({
				id
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<button class="btn btn-sm gap-2" on:click={handleSubmit}>
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
		<path
			d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
			transform="rotate(-45 12 12)"
		/>
	</svg>
	Force re-Deploy
</button>
