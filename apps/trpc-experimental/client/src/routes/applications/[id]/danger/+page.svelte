<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	let application: any = data.application.data;
	import { page } from '$app/stores';
	import { appSession, status, trpc } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	let forceDelete = false;
	async function deleteApplication(name: string, force: boolean) {
		const sure = confirm('Are you sure you want to delete this application?');
		if (sure) {
			$status.application.initialLoading = true;
			try {
				await trpc.applications.deleteApplication.mutate({ id, force });
				return await goto('/');
			} catch (error) {
				if (error.message.startsWith(`Command failed: SSH_AUTH_SOCK=/tmp/coolify-ssh-agent.pid`)) {
					forceDelete = true;
				}
				return errorNotification(error);
			} finally {
				$status.application.initialLoading = false;
			}
		}
	}
</script>

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Danger Zone</div>
	</div>

	{#if forceDelete}
		<button
			id="forcedelete"
			on:click={() => deleteApplication(application.name, true)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:bg-red-600={$appSession.isAdmin}
			class:hover:bg-red-500={$appSession.isAdmin}
			class="btn btn-lg btn-error hover:bg-red-700 text-sm w-64"
		>
			Force Delete Application
		</button>
	{:else}
		<button
			id="delete"
			on:click={() => deleteApplication(application.name, false)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class="btn btn-lg btn-error hover:bg-red-700 text-sm w-64"
		>
			Delete Application
		</button>
	{/if}
</div>
