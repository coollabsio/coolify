<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/iam`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let account: any;
	export let accounts: any;
	export let invitations: any;
	export let ownTeams: any;
	export let allTeams: any;

	import { page } from '$app/stores';
	import { del, get, post } from '$lib/api';
	import { toast } from '@zerodevx/svelte-toast';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	import { goto } from '$app/navigation';
	import Cookies from 'js-cookie';
	if (accounts.length === 0) {
		accounts.push(account);
	}

	async function resetPassword(id: any) {
		const sure = window.confirm('Are you sure you want to reset the password?');
		if (!sure) {
			return;
		}
		try {
			await post(`/iam/user/password`, { id });
			toast.push('Password reset successfully. Please relogin to reset it.');
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteUser(id: any) {
		const sure = window.confirm('Are you sure you want to delete this user?');
		if (!sure) {
			return;
		}
		try {
			await del(`/iam/user/remove`, { uid: id });
			toast.push('Account deleted.');
			const data = await get('/iam');
			accounts = data.accounts;
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function acceptInvitation(id: any, teamId: any) {
		try {
			await post(`/iam/team/${teamId}/invitation/accept`, { id });
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id: any, teamId: any) {
		try {
			await post(`/iam/team/${teamId}/invitation/revoke`, { id });
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function switchTeam(selectedTeamId: any) {
		try {
			const payload = await get(`/user?teamId=${selectedTeamId}`);
			if (payload.token) {
				Cookies.set('token', payload.token, {
					path: '/'
				});
				$appSession.teamId = payload.teamId;
				$appSession.userId = payload.userId;
				$appSession.permission = payload.permission;
				$appSession.isAdmin = payload.isAdmin;
				return window.location.reload();
			}
		} catch (error) {
			console.error(error);
			return errorNotification(error);
		}
	}
	async function newTeam() {
		const { id } = await post('/iam/new', {});
		return await goto(`/iam/team/${id}`, { replaceState: true });
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Identity and Access Management</div>
	<div on:click={newTeam} class="add-icon cursor-pointer bg-fuchsia-600 hover:bg-fuchsia-500">
		<svg
			class="w-6"
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
			><path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M12 6v6m0 0v6m0-6h6m-6 0H6"
			/></svg
		>
	</div>
</div>

{#if invitations.length > 0}
	<div class="mx-auto max-w-4xl px-6 py-4">
		<div class="title font-bold">Pending invitations</div>
		<div class="pt-10 text-center">
			{#each invitations as invitation}
				<div class="flex justify-center space-x-2">
					<div>
						Invited to <span class="font-bold text-pink-600">{invitation.teamName}</span> with
						<span class="font-bold text-rose-600">{invitation.permission}</span> permission.
					</div>
					<button
						class="hover:bg-green-500"
						on:click={() => acceptInvitation(invitation.id, invitation.teamId)}>Accept</button
					>
					<button
						class="hover:bg-red-600"
						on:click={() => revokeInvitation(invitation.id, invitation.teamId)}>Delete</button
					>
				</div>
			{/each}
		</div>
	</div>
{/if}
<div class="mx-auto max-w-4xl px-6 py-4">
	{#if $appSession.teamId === '0' && accounts.length > 0}
		<div class="title font-bold">Accounts</div>
	{:else}
		<div class="title font-bold">Account</div>
	{/if}
	<div class="flex items-center justify-center pt-10">
		<table class="mx-2 text-left">
			<thead class="mb-2">
				<tr>
					{#if accounts.length > 1}
						<th class="px-2">Email</th>
						<th>Actions</th>
					{/if}
				</tr>
			</thead>

			<tbody>
				{#each accounts as account}
					<tr>
						<td class="px-2">{account.email}</td>
						<td class="flex space-x-2">
							<form on:submit|preventDefault={() => resetPassword(account.id)}>
								<button
									class="mx-auto my-4 w-32 bg-fuchsia-600 hover:bg-fuchsia-500 disabled:bg-coolgray-200"
									>Reset Password</button
								>
							</form>
							<form on:submit|preventDefault={() => deleteUser(account.id)}>
								<button
									disabled={account.id === $appSession.userId}
									class="mx-auto my-4 w-32 bg-red-600 hover:bg-red-500 disabled:bg-coolgray-200"
									type="submit">Delete User</button
								>
							</form>
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
</div>

<div class="mx-auto max-w-4xl px-6">
	<div class="title font-bold">Teams</div>
	<div class="flex items-center justify-center pt-10">
		<div class="flex flex-col">
			<div class="flex flex-row flex-wrap justify-center px-2 pb-10 md:flex-row">
				{#each ownTeams as team}
					<a href="/iam/team/{team.id}" class="w-96 p-2 no-underline">
						<div class="box-selection relative">
							<div>
								<div class="truncate text-center text-xl font-bold">
									{team.name}
								</div>
								<div class="mt-1 text-center text-xs">
									{team.permissions?.length} member(s)
								</div>
							</div>
							<div class="flex items-center justify-center pt-3">
								<button
									on:click|preventDefault={() => switchTeam(team.id)}
									class:bg-fuchsia-600={$appSession.teamId !== team.id}
									class:hover:bg-fuchsia-500={$appSession.teamId !== team.id}
									class:bg-transparent={$appSession.teamId === team.id}
									disabled={$appSession.teamId === team.id}
									>{$appSession.teamId === team.id ? 'Current Team' : 'Switch Team'}</button
								>
							</div>
						</div>
					</a>
				{/each}
			</div>
			{#if $appSession.teamId === '0' && allTeams.length > 0}
				<div class="pb-5 pt-10 text-xl font-bold">Other Teams</div>
				<div class="flex flex-row flex-wrap justify-center px-2 md:flex-row">
					{#each allTeams as team}
						<a href="/iam/team/{team.id}" class="w-96 p-2 no-underline">
							<div
								class="box-selection relative"
								class:hover:bg-fuchsia-600={team.id !== '0'}
								class:hover:bg-red-500={team.id === '0'}
							>
								<div class="truncate text-center text-xl font-bold">
									{team.name}
								</div>

								<div class="mt-1 text-center">{team.permissions?.length} member(s)</div>
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	</div>
</div>
