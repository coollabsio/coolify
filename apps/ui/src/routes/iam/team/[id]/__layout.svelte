<script context="module" lang="ts">
	import { del, get } from '$lib/api';
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, url }) => {
		try {
			const response = await get(`/iam/team/${params.id}`);
			if (!response.permissions || Object.entries(response.permissions).length === 0) {
				return {
					status: 302,
					redirect: '/iam'
				};
			}
			return {
				props: {
					...response
				},
				stuff: {
					...response
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession } from '$lib/store';
	import { t } from '$lib/translations';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { goto } from '$app/navigation';
	const { id } = $page.params;
	async function deleteTeam() {
		const sure = confirm('Are you sure you want to delete this team?');
		if (sure) {
			try {
				await del(`/iam/team/${id}`, { id });
				return await goto('/iam', { replaceState: true });
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
</script>

{#if id !== 'new'}
	<nav class="nav-side">
		<button
			on:click={deleteTeam}
			title={$t('source.delete_git_source')}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:hover:text-red-500={$appSession.isAdmin}
			class="icons tooltip-bottom bg-transparent text-sm"
			data-tooltip={$appSession.isAdmin
				? 'Delete Team'
				: $t('destination.permission_denied_delete_destination')}><DeleteIcon /></button
		>
	</nav>
{/if}
<slot />
