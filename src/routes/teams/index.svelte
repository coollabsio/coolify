<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = `/teams.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import { errorNotification } from '$lib/form';
	import { session } from '$app/stores';
	import { post } from '$lib/api';

	export let teams;
	export let invitations;

	async function acceptInvitation(id, teamId) {
		try {
			await post(`/teams/${teamId}/invitation/accept.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id, teamId) {
		try {
			await post(`/teams/${teamId}/invitation/revoke.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
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
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Teams</div>
	{#if $session.isAdmin}
		<a href="/new/team" class="add-icon bg-cyan-600 hover:bg-cyan-500">
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
	{/if}
</div>

{#if invitations.length > 0}
	<div class="mx-auto max-w-2xl pb-10">
		<div class="flex space-x-1 p-6 font-bold">
			<div class="title">Pending invitations</div>
		</div>
		<div class="text-center">
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
<div class="flex flex-wrap justify-center">
	<div class="flex flex-col">
		<div class="-ml-10 pb-5 text-xl font-bold">Current Team</div>
		<div class="flex flex-col flex-wrap md:flex-row">
			{#each ownTeams as team}
				<a href="/teams/{team.teamId}" class="w-96 p-2 no-underline">
					<div
						class="box-selection relative"
						class:hover:bg-cyan-600={team.team?.id !== '0'}
						class:hover:bg-red-500={team.team?.id === '0'}
					>
						<div class="truncate text-center text-xl font-bold">
							{team.team.name}
							{team.team?.id === '0' ? '(admin team)' : ''}
						</div>

						<div class="mt-1 text-center">{team.team._count.users} member(s)</div>
					</div>
				</a>
			{/each}
		</div>

		<div class="-ml-10 pb-5 pt-10 text-xl  font-bold">Other Teams</div>
		<div class="flex flex-col flex-wrap md:flex-row">
			{#each otherTeams as team}
				<a href="/teams/{team.teamId}" class="w-96 p-2 no-underline">
					<div
						class="box-selection relative"
						class:hover:bg-cyan-600={team.team?.id !== '0'}
						class:hover:bg-red-500={team.team?.id === '0'}
					>
						<div class="truncate text-center text-xl font-bold">
							{team.team.name}
							{team.team?.id === '0' ? '(admin team)' : ''}
						</div>

						<div class="mt-1 text-center">{team.team._count.users} member(s)</div>
					</div>
				</a>
			{/each}
		</div>
	</div>
</div>
