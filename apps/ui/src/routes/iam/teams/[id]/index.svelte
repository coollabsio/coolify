<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let currentTeam: string;
	export let teams: any[];
	export let permissions: any;
	export let team: any;
	export let invitations: any[];

	import { page } from '$app/stores';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import { del, get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession } from '$lib/store';
	import Explainer from '$lib/components/Explainer.svelte';
	import Cookies from 'js-cookie';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	let invitation: any = {
		teamName: team.name,
		email: null,
		permission: 'read'
	};
	function isAdmin(permission: string) {
		if (permission === 'admin' || permission === 'owner') {
			return true;
		}

		return false;
	}

	async function sendInvitation() {
		try {
			await post(`/iam/team/${id}/invitation/invite`, {
				teamId: team.id,
				teamName: invitation.teamName,
				email: invitation.email.toLowerCase(),
				permission: invitation.permission
			});
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id: string) {
		try {
			await post(`/iam/team/${id}/invitation/revoke`, { id });
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function removeFromTeam(uid: string) {
		try {
			await post(`/iam/team/${id}/user/remove`, { uid });
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function changePermission(userId: string, permissionId: string, currentPermission: string) {
		let newPermission = 'read';
		if (currentPermission === 'read') {
			newPermission = 'admin';
		}
		try {
			await post(`/iam/team/${id}/permission`, { userId, newPermission, permissionId });
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/iam/team/${id}`, { ...team });
			return addToast({
				message: 'Settings updated.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteTeam() {
		const sure = confirm('Are you sure you want to delete this team?');
		if (sure) {
			try {
				const switchTeam = teams.find((team: any) => team.id !== id);
				if (!switchTeam) {
					return addToast({
						message: 'You cannot delete your last team.',
						type: 'error'
					});
				}
				await del(`/iam/team/${id}`, { id });
				if (currentTeam === id) {
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
				return await goto('/iam/teams', { replaceState: true });
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	async function leaveTeam(uid: string) {
		const sure = confirm('Are you sure you want to leave this team?');
		if (sure) {
			try {
				const switchTeam = teams.find((team: any) => team.id !== id);
				const foundAdmin = team.permissions.filter(
					(permission: any) => permission.userId !== uid && permission.permission === 'admin'
				);
				if (!switchTeam) {
					return addToast({
						message: 'You cannot leave your last team.',
						type: 'error'
					});
				}
				if (!foundAdmin.length) {
					return addToast({
						message: 'You cannot leave this team without an admin.',
						type: 'error'
					});
				}
				await post(`/iam/team/${id}/user/remove`, { uid });
				if (currentTeam === id) {
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
				return await goto('/iam/teams', { replaceState: true });
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 items-center  pb-3">
			<div class="title font-bold">{team.name}</div>

			<button class="btn btn-sm bg-primary" on:click={handleSubmit}>{$t('forms.save')}</button>
			<button
				id="delete"
				on:click={deleteTeam}
				type="submit"
				disabled={!$appSession.isAdmin}
				class="btn btn-sm bg-error">Remove Team</button
			>
		</div>
	</div>
</div>

<div class="mx-auto">
	<div class="flex space-x-1 pb-5">
		<div class="title font-bold">{$t('index.settings')}</div>
	</div>
	<div class="grid grid-flow-row gap-2 px-4">
		<div class="mt-2 grid grid-cols-2">
			<div class="flex-col">
				<label for="name">{$t('forms.name')}</label>
				{#if team.id === '0'}
					<Explainer explanation={$t('team.root_team_explainer')} />
				{/if}
			</div>
			<input id="name" name="name" placeholder="name" bind:value={team.name} class="input w-full" />
		</div>
	</div>

	<div class="flex space-x-1 py-5 pt-10 font-bold">
		<div class="title">{$t('team.members')}</div>
	</div>
	<div class="px-4">
		<table class="w-full border-separate text-left">
			<thead>
				<tr class="h-8 border-b border-coolgray-400">
					<th scope="col">{$t('forms.email')}</th>
					<th scope="col">{$t('team.permission')}</th>
					<th scope="col" class="text-center">{$t('forms.action')}</th>
				</tr>
			</thead>
			{#each permissions as permission}
				<tr class="text-xs">
					<td class="py-4"
						>{permission.user.email}
						{#if permission.user.id === $appSession.userId}
							<span class="font-bold badge badge-primary text-xs">{$t('team.you')}</span>
						{/if}
					</td>
					<td class="py-4">{permission.permission}</td>
					{#if $appSession.isAdmin && permission.user.id !== $appSession.userId && permission.permission !== 'owner'}
						<td
							class="flex flex-col lg:flex-row justify-center lg:space-y-0 space-y-2 space-x-0 lg:space-x-2 text-center"
						>
							<button
								class="btn btn-sm"
								on:click={() =>
									changePermission(permission.user.id, permission.id, permission.permission)}
								>{$t('team.promote_to', {
									grade: permission.permission === 'admin' ? 'Read' : 'Admin'
								})}</button
							>
							<button
								class="btn btn-sm btn-error"
								on:click={() => removeFromTeam(permission.user.id)}>{$t('forms.remove')}</button
							>
						</td>
					{:else if permission.user.id === $appSession.userId}
						<td class="py-4 flex flex-row justify-center">
							<button class="btn btn-sm btn-primary" on:click={() => leaveTeam(permission.user.id)}
								>Leave Team</button
							>
						</td>
					{:else}
						<td class="text-center py-4 flex-col space-y-2">
							{$t('forms.no_actions_available')}
						</td>
					{/if}
				</tr>
			{/each}

			{#each invitations as invitation}
				<tr class="text-xs">
					<td class="py-4 font-bold text-yellow-500">{invitation.email} </td>
					<td class="py-4 font-bold text-yellow-500">{invitation.permission}</td>
					{#if isAdmin(team.permissions[0].permission)}
						<td class="flex-col space-y-2 py-4 text-center">
							<button class="btn btn-sm btn-error" on:click={() => revokeInvitation(invitation.id)}
								>{$t('team.revoke_invitation')}</button
							>
						</td>
					{:else}
						<td class="text-center py-4 flex-col space-y-2">{$t('team.pending_invitation')}</td>
					{/if}
				</tr>
			{/each}
		</table>
	</div>
	{#if $appSession.isAdmin}
		<form on:submit|preventDefault={sendInvitation} class="py-5 pt-10">
			<div class="flex space-x-1">
				<div class="flex space-x-1">
					<div class="title font-bold">{$t('team.invite_new_member')}</div>
					<button class="btn btn-sm bg-primary" type="submit">{$t('team.send_invitation')}</button>
				</div>
			</div>
			<SimpleExplainer text={$t('team.invite_only_register_explainer')} />
			<div class="flex-col pt-5">
				<div class="flex space-x-0">
					<input
						bind:value={invitation.email}
						placeholder={$t('forms.email')}
						class="input mr-2 w-full"
						required
					/>
					<div class="flex-1" />
					<button
						on:click={() => (invitation.permission = 'read')}
						class="px-2 rounded-none rounded-l border border-dashed border-transparent"
						type="button"
						class:border-coolgray-300={invitation.permission !== 'read'}
						class:bg-fuchsia-500={invitation.permission === 'read'}>{$t('team.read')}</button
					>
					<button
						on:click={() => (invitation.permission = 'admin')}
						class="px-2 rounded-none rounded-r border border-dashed border-transparent"
						type="button"
						class:border-coolgray-300={invitation.permission !== 'admin'}
						class:bg-red-500={invitation.permission === 'admin'}>{$t('team.admin')}</button
					>
				</div>
			</div>
		</form>
	{/if}
</div>
