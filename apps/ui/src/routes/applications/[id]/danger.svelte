<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/secrets`);
			return {
				props: {
					application: stuff.application,
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let application: any;
	import { page } from '$app/stores';
	import { del, get } from '$lib/api';
	import { t } from '$lib/translations';
	import { appSession, status } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	let forceDelete = false;
	async function deleteApplication(name: string, force: boolean) {
		const sure = confirm($t('application.confirm_to_delete', { name }));
		if (sure) {
			$status.application.initialLoading = true;
			try {
				await del(`/applications/${id}`, { id, force });
				return await goto('/')
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
