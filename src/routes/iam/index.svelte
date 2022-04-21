<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = `/iam.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}
		if (res.status === 401) {
			return {
				status: 302,
				redirect: '/'
			};
		}
		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import { session } from '$app/stores';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { toast } from '@zerodevx/svelte-toast';

	export let account;
	export let accounts;
	export let invitations;
	if (accounts.length === 0) {
		accounts.push(account);
	}
	export let ownTeams;
	export let allTeams;

	async function resetPassword(id) {
		const sure = window.confirm('Are you sure you want to reset the password?');
		if (!sure) {
			return;
		}
		try {
			await post(`/iam/password.json`, { id });
			toast.push('Password reset successfully. Please relogin to reset it.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function deleteUser(id) {
		const sure = window.confirm('Are you sure you want to delete this user?');
		if (!sure) {
			return;
		}
		try {
			await post(`/iam.json`, { id });
			toast.push('Account deleted.');
			const data = await get('/iam.json');
			accounts = data.accounts;
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function acceptInvitation(id, teamId) {
		try {
			await post(`/iam/team/${teamId}/invitation/accept.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id, teamId) {
		try {
			await post(`/iam/team/${teamId}/invitation/revoke.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Identity and Access Management</div>
	<a href="/new/team" class="add-icon cursor-pointer bg-fuchsia-600 hover:bg-fuchsia-500">
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
	</a>
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
	{#if $session.teamId === '0' && accounts.length > 0}
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
									class="mx-auto my-4 w-32 bg-coollabs hover:bg-coollabs-100 disabled:bg-coolgray-200"
									>Reset Password</button
								>
							</form>
							<form on:submit|preventDefault={() => deleteUser(account.id)}>
								<button
									disabled={account.id === $session.userId}
									class="mx-auto my-4 w-32 bg-coollabs hover:bg-coollabs-100 disabled:bg-coolgray-200"
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
						<div
							class="box-selection relative"
							class:hover:bg-cyan-600={team.id !== '0'}
							class:hover:bg-red-500={team.id === '0'}
						>
							<div class="truncate text-center text-xl font-bold">
								{team.name}
							</div>
							<div class="truncate text-center font-bold">
								{team.id === '0' ? 'root team' : ''}
							</div>

							<div class:mt-6={team.id !== '0'} class="mt-1 text-center">
								{team.permissions?.length} member(s)
							</div>
						</div>
					</a>
				{/each}
			</div>
			{#if $session.teamId === '0' && allTeams.length > 0}
				<div class="pb-5 pt-10 text-xl font-bold">Other Teams</div>
				<div class="flex flex-row flex-wrap justify-center px-2 md:flex-row">
					{#each allTeams as team}
						<a href="/iam/team/{team.id}" class="w-96 p-2 no-underline">
							<div
								class="box-selection relative"
								class:hover:bg-cyan-600={team.id !== '0'}
								class:hover:bg-red-500={team.id === '0'}
							>
								<div class="truncate text-center text-xl font-bold">
									{team.name}
								</div>
								<div class="truncate text-center font-bold">
									{team.id === '0' ? 'root team' : ''}
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
