<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
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

	export let teams;
	export let invitations;

	async function acceptInvitation(id, teamId) {
		const form = new FormData();
		form.append('id', id);
		const response = await fetch(`/teams/${teamId}/invitation/accept.json`, {
			method: 'post',
			body: form
		});
		if (!response.ok) {
			const { message } = await response.json();
			errorNotification(message);
			return;
		}
		window.location.reload();
	}
	async function revokeInvitation(id, teamId) {
		const form = new FormData();
		form.append('id', id);
		const response = await fetch(`/teams/${teamId}/invitation/revoke.json`, {
			method: 'post',
			body: form
		});
		if (!response.ok) {
			const { message } = await response.json();
			errorNotification(message);
			return;
		}
		window.location.reload();
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Teams</div>
</div>

{#if invitations.length > 0}
	<div class="max-w-2xl mx-auto pb-10">
		<div class="font-bold flex space-x-1 py-5 px-6">
			<div class="text-xl tracking-tight mr-4">Pending invitations</div>
		</div>
		<div class="text-center">
			{#each invitations as invitation}
				<div class="flex justify-center space-x-2">
					<div>
						Invited to <span class="text-pink-600 font-bold">{invitation.teamName}</span> with
						<span class="text-rose-600 font-bold">{invitation.permission}</span> permission.
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
<div class="max-w-2xl mx-auto">
	<div class="flex flex-wrap justify-center">
		{#each teams as team}
			<a href="/teams/{team.teamId}" class="no-underline p-2 ">
				<div
					class="box-selection h-32"
					class:border-cyan-500={team.teamId !== '0'}
					class:border-red-500={team.teamId === '0'}
				>
					<div class="font-bold text-xl text-center truncate">{team.team.name}</div>
					<div class="text-center text-xs">({team.permission})</div>
					<div class="text-center">Members: {team.team._count.users}</div>
					{#if team.team?.id === '0'}
						<div class="text-center text-xs text-red-500">root team</div>
					{/if}
				</div>
			</a>
		{/each}
	</div>
</div>
