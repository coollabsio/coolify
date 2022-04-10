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
	if (accounts.length === 0) {
		accounts.push(account);
	}
	export let teams;

	const ownTeams = teams.filter((team) => {
		if (team.team.id === $session.teamId) {
			return team;
		}
	});
	const otherTeams = teams.filter((team) => {
		if (team.team.id !== $session.teamId) {
			return team;
		}
	});

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
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Identity and Access Management</div>
</div>

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
			<div class="flex flex-col flex-wrap justify-center px-2 pb-10 md:flex-row">
				{#each ownTeams as team}
					<a href="/iam/team/{team.teamId}" class="w-96 p-2 no-underline">
						<div
							class="box-selection relative"
							class:hover:bg-cyan-600={team.team?.id !== '0'}
							class:hover:bg-red-500={team.team?.id === '0'}
						>
							<div class="truncate text-center text-xl font-bold">
								{team.team.name}
							</div>
							<div class="truncate text-center font-bold">
								{team.team?.id === '0' ? 'root team' : ''}
							</div>

							<div class="mt-1 text-center">{team.team._count.users} member(s)</div>
						</div>
					</a>
				{/each}
			</div>
			{#if $session.teamId === '0' && otherTeams.length > 0}
				<div class="pb-5 pt-10 text-xl font-bold">Other Teams</div>
			{/if}
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each otherTeams as team}
					<a href="/iam/team/{team.teamId}" class="w-96 p-2 no-underline">
						<div
							class="box-selection relative"
							class:hover:bg-cyan-600={team.team?.id !== '0'}
							class:hover:bg-red-500={team.team?.id === '0'}
						>
							<div class="truncate text-center text-xl font-bold">
								{team.team.name}
							</div>
							<div class="truncate text-center font-bold">
								{team.team?.id === '0' ? 'root team' : ''}
							</div>

							<div class="mt-1 text-center">{team.team._count.users} member(s)</div>
						</div>
					</a>
				{/each}
			</div>
		</div>
	</div>
</div>
