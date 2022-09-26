<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let permissions: any;
	export let team: any;
	export let invitations: any[];
	import { page } from '$app/stores';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import { post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
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
			await post(`/iam/team/${id}/user/remove`, { teamId: team.id, uid });
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
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 px-6 text-2xl font-bold">
	<div class="tracking-tight">{$t('index.team')}</div>
	<span class="arrow-right-applications px-1 text-fuchsia-500">></span>
	<span class="pr-2">{team.name}</span>
</div>
<div class="mx-auto max-w-6xl px-6">
	<form on:submit|preventDefault={handleSubmit} class=" py-4">
		<div class="flex space-x-1 pb-5">
			<div class="title font-bold">{$t('index.settings')}</div>
			<button class="btn btn-sm bg-iam" type="submit">{$t('forms.save')}</button>
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="mt-2 grid grid-cols-2">
				<div class="flex-col">
					<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
					{#if team.id === '0'}
						<SimpleExplainer customClass="w-full" text={$t('team.root_team_explainer')} />
					{/if}
				</div>
				<input id="name" name="name" placeholder="name" bind:value={team.name} />
			</div>
		</div>
	</form>

	<div class="flex space-x-1 py-5 pt-10 font-bold">
		<div class="title">{$t('team.members')}</div>
	</div>
	<div class="px-4 sm:px-6">
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
						<span class="font-bold"
							>{permission.user.id === $appSession.userId ? $t('team.you') : ''}</span
						></td
					>
					<td class="py-4">{permission.permission}</td>
					{#if $appSession.isAdmin && permission.user.id !== $appSession.userId && permission.permission !== 'owner'}
						<td class="flex flex-col items-center justify-center space-y-2 py-4 text-center">
							<button
								class="btn btn-sm btn-error"
								on:click={() => removeFromTeam(permission.user.id)}>{$t('forms.remove')}</button
							>
							<button
								class="btn btn-sm"
								on:click={() =>
									changePermission(permission.user.id, permission.id, permission.permission)}
								>{$t('team.promote_to', {
									grade: permission.permission === 'admin' ? 'read' : 'admin'
								})}</button
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
							<button
								class="btn btn-sm btn-error"
								on:click={() => revokeInvitation(invitation.id)}
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
					<button class="btn btn-sm bg-iam" type="submit"
						>{$t('team.send_invitation')}</button
					>
				</div>
			</div>
			<SimpleExplainer text={$t('team.invite_only_register_explainer')} />
			<div class="flex-col space-y-2 px-4 pt-5 sm:px-6">
				<div class="flex space-x-0">
					<input
						bind:value={invitation.email}
						placeholder={$t('forms.email')}
						class="mr-2 w-full"
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
