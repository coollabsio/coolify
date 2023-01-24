<script lang="ts">
	import { goto } from '$app/navigation';
	import { errorNotification } from '$lib/common';
	import { appSession, trpc } from '$lib/store';

	export let id: string;
	export let name: string;
	export let force: boolean = false;

	async function handleSubmit() {
		const sure = confirm(`Are you sure you want to delete ${name}?`);
		if (sure) {
			try {
				await trpc.applications.delete.mutate({ id, force });
				return await goto('/');
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
</script>

<button
	on:click={handleSubmit}
	disabled={!$appSession.isAdmin}
	class="btn btn-sm btn-error hover:bg-red-700 text-sm w-64"
>
	{force ? 'Force' : ''} Delete Application
</button>
