<script lang="ts">
	import { errorNotification } from '$lib/common';
	export let id: string;
	import { trpc } from '$lib/store';
	async function handleSubmit() {
		try {
			await trpc.applications.deploy.mutate({
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
		class="w-6 h-6 text-pink-500"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<path d="M7 4v16l13 -8z" />
	</svg>
	Deploy
</button>
