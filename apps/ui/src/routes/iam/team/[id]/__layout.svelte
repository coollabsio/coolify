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
	export let team: any;
	export let currentTeam: string;
	export let teams: any;
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession } from '$lib/store';
	import { t } from '$lib/translations';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { goto } from '$app/navigation';
	import Cookies from 'js-cookie';
	const { id } = $page.params;

	async function deleteTeam() {
		const sure = confirm('Are you sure you want to delete this team?');
		if (sure) {
			try {
				await del(`/iam/team/${id}`, { id });
				if (currentTeam === id) {
					const switchTeam = teams.find((team: any) => team.id !== id);
					const payload = await get(`/user?teamId=${switchTeam.id}`);
					if (payload.token) {
						Cookies.set('token', payload.token, {
							path: '/'
						});
						$appSession.teamId = payload.teamId;
						$appSession.userId = payload.userId;
						$appSession.permission = payload.permission;
						$appSession.isAdmin = payload.isAdmin;
						return window.location.assign('/iam');
					}
				}

				return await goto('/iam', { replaceState: true });
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
</script>

{#if id !== 'new'}
	<nav class="nav-side">
		{#if team.id !== '0'}
			<button
				on:click={deleteTeam}
				type="submit"
				disabled={!$appSession.isAdmin}
				class:hover:text-red-500={$appSession.isAdmin}
				class="icons tooltip tooltip-primary tooltip-left bg-transparent text-sm"
				data-tip={$appSession.isAdmin
					? 'Delete'
					: $t('destination.permission_denied_delete_destination')}><DeleteIcon /></button
			>
		{/if}
	</nav>
{/if}
<slot />
