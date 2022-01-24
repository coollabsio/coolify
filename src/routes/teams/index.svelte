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
	import { session } from '$app/stores';

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
					class="box-selection h-32  relative"
					class:hover:bg-cyan-600={team.team?.id !== '0'}
					class:hover:bg-red-500={team.team?.id === '0'}
				>
					<div class="font-bold text-xl text-center truncate">{team.team.name}</div>
					<div class="text-center text-xs">
						({team.team?.id === '0' ? 'root team - ' : ''}{team.permission})
					</div>

					<div class="text-center mt-1">{team.team._count.users} member(s)</div>
				</div>
			</a>
		{/each}
	</div>
</div>
