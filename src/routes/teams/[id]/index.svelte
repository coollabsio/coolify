<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		const url = `/teams/${params.id}.json`;
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
	export let permissions;
	export let team;
	export let invitations;
	import { page, session } from '$app/stores';
	import Explainer from '$lib/components/Explainer.svelte';
	import { errorNotification } from '$lib/form';
	import { post } from '$lib/api';
	const { id } = $page.params;

	let invitation = {
		teamName: team.name,
		email: null,
		permission: 'read'
	};
	let myPermission = permissions.find((u) => u.user.id === $session.uid).permission;
	function isAdmin(permission = myPermission) {
		if (myPermission === 'admin' || myPermission === 'owner') {
			return true;
		}

		return false;
	}

	async function sendInvitation() {
		try {
			await post(`/teams/${id}/invitation/invite.json`, {
				teamId: team.id,
				teamName: invitation.teamName,
				email: invitation.email,
				permission: invitation.permission
			});
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id) {
		try {
			await post(`/teams/${id}/invitation/revoke.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function removeFromTeam(uid) {
		try {
			await post(`/teams/${id}/remove/user.json`, { teamId: team.id, uid });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function changePermission(userId, permissionId, currentPermission) {
		let newPermission = 'read';
		if (currentPermission === 'read') {
			newPermission = 'admin';
		}
		try {
			await post(`/teams/${id}/permission/change.json`, { userId, newPermission, permissionId });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/teams/${id}.json`, { ...team });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-5 px-6 text-2xl font-bold">
	<div class="tracking-tight">Team</div>
	<span class="arrow-right-applications px-1">></span>
	<span class="pr-2">{team.name}</span>
</div>
<div class="mx-auto max-w-2xl">
	<form on:submit|preventDefault={handleSubmit}>
		<div class="flex space-x-1 py-5 px-6 font-bold">
			<div class="mr-4 text-xl tracking-tight">Settings</div>
			<div class="text-center">
				<button class="bg-green-600 hover:bg-green-500" type="submit">Save</button>
			</div>
		</div>

		<div class="mx-2 flex items-center space-x-2 px-4 sm:px-6">
			<label for="name">Name</label>
			<input id="name" name="name" placeholder="name" bind:value={team.name} />
		</div>
		{#if team.id === '0'}
			<div class="pt-4 text-center">
				<Explainer
					maxWidthClass="w-full"
					text="This is the <span class='text-red-500 font-bold'>root</span> team. <br><br>That means members of this group can manage instance wide settings and have all the priviliges in Coolify. <br>(imagine like root user on Linux)"
				/>
			</div>
		{/if}
	</form>

	<div class="flex space-x-1 py-5 px-6 pt-10 font-bold">
		<div class="mr-4 text-xl tracking-tight">Members</div>
	</div>
	<div class="px-4 sm:px-6">
		<table class="mx-2 w-full table-auto text-left">
			<tr class="h-8 border-b border-coolgray-400">
				<th scope="col">Email</th>
				<th scope="col">Permission</th>
				<th scope="col" class="text-center">Actions</th>
			</tr>
			{#each permissions as permission}
				<tr class="text-xs">
					<td class="py-4"
						>{permission.user.email}
						<span class="font-bold">{permission.user.id === $session.uid ? '(You)' : ''}</span></td
					>
					<td class="py-4">{permission.permission}</td>
					{#if $session.isAdmin && permission.user.id !== $session.uid && permission.permission !== 'owner'}
						<td class="flex flex-col items-center justify-center space-y-2 py-4 text-center">
							<button
								class="w-52 bg-red-600 hover:bg-red-500"
								on:click={() => removeFromTeam(permission.user.id)}>Remove</button
							>
							<button
								class="w-52"
								on:click={() =>
									changePermission(permission.user.id, permission.id, permission.permission)}
								>Promote to {permission.permission === 'admin' ? 'read' : 'admin'}</button
							>
						</td>
					{:else}
						<td class="text-center py-4 flex-col space-y-2"> No actions available </td>
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
								class="w-52 bg-red-600 hover:bg-red-500"
								on:click={() => revokeInvitation(invitation.id)}>Revoke invitation</button
							>
						</td>
					{:else}
						<td class="text-center py-4 flex-col space-y-2">Pending invitation</td>
					{/if}
				</tr>
			{/each}
		</table>
	</div>
</div>
{#if $session.isAdmin}
	<div class="mx-auto max-w-2xl pt-8">
		<form on:submit|preventDefault={sendInvitation}>
			<div class="flex space-x-1 py-5 px-6 font-bold">
				<div class="mr-4 text-xl tracking-tight">Invite new member</div>
				<div class="text-center">
					<button class="bg-green-600 hover:bg-green-500" type="submit">Send invitation</button>
				</div>
			</div>
			<div class="flex-col space-y-2 px-4 sm:px-6">
				<div class="flex space-x-0">
					<input
						bind:value={invitation.email}
						placeholder="Email address"
						class="mr-2 w-full"
						required
					/>
					<div class="flex-1" />
					<button
						on:click={() => (invitation.permission = 'read')}
						class="rounded-none rounded-l"
						type="button"
						class:bg-pink-500={invitation.permission === 'read'}>Read</button
					>
					<button
						on:click={() => (invitation.permission = 'admin')}
						class="rounded-none rounded-r"
						type="button"
						class:bg-red-500={invitation.permission === 'admin'}>Admin</button
					>
				</div>
			</div>
		</form>
	</div>
{/if}
