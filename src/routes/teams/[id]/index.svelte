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
	import { enhance, errorNotification } from '$lib/form';
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
		const form = new FormData();
		form.append('teamId', team.id);
		form.append('teamName', invitation.teamName);
		form.append('email', invitation.email);
		form.append('permission', invitation.permission);
		const response = await fetch(`/teams/${id}/invitation/invite.json`, {
			method: 'post',
			body: form
		});
		if (!response.ok) {
			const { message } = await response.json();
			errorNotification(message);
			invitation.email = null;
			return;
		}
		window.location.reload();
	}
	async function revokeInvitation(id) {
		const form = new FormData();
		form.append('id', id);
		const response = await fetch(`/teams/${id}/invitation/revoke.json`, {
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
	async function removeFromTeam(uid) {
		const form = new FormData();
		form.append('teamId', team.id);
		form.append('uid', uid);
		const response = await fetch(`/teams/${id}/remove/user.json`, {
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
	async function changePermission(userId, permissionId, currentPermission) {
		let newPermission = 'read';
		if (currentPermission === 'read') {
			newPermission = 'admin';
		}
		const form = new FormData();
		form.append('userId', userId);
		form.append('newPermission', newPermission);
		form.append('permissionId', permissionId);
		const response = await fetch(`/teams/${id}/permission/change.json`, {
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

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl">
	<div class="tracking-tight">Team</div>
	<span class="px-1 arrow-right-applications">></span>
	<span class="pr-2">{team.name}</span>
</div>
<div class="max-w-2xl mx-auto">
	{#if team.id === '0'}
		<div class="text-center">
			<Explainer
				maxWidthClass="w-full"
				text="This is the <span class='text-red-500 font-bold'>root</span> team. <br><br>That means members of this group can manage instance wide settings and have all the priviliges in Coolify. <br>(imagine like root user on Linux)"
			/>
		</div>
	{/if}
	<form
		action="/teams/{id}.json"
		method="post"
		use:enhance={{
			result: async () => {
				window.location.reload();
			},
			pending: async () => {},
			final: async () => {}
		}}
	>
		<div class="font-bold flex space-x-1 py-5 px-6">
			<div class="text-xl tracking-tight mr-4">Settings</div>
			<div class="text-center">
				<button class="bg-green-600 hover:bg-green-500" type="submit">Save</button>
			</div>
		</div>

		<div class="flex space-x-2 px-4 sm:px-6 mx-2 items-center">
			<label for="name">Name</label>
			<input id="name" name="name" placeholder="name" bind:value={team.name} />
		</div>
	</form>

	<div class="font-bold flex space-x-1 py-5 px-6 pt-24">
		<div class="text-xl tracking-tight mr-4">Members</div>
	</div>
	<div class="px-4 sm:px-6">
		<table class="mx-2 w-full text-left table-auto">
			<tr class="border-b border-coolgray-400 h-8">
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
						<td class="text-center py-4 flex justify-center items-center flex-col space-y-2">
							<button
								class="bg-red-600 hover:bg-red-500 w-52"
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
					<td class="py-4 text-yellow-500 font-bold">{invitation.email} </td>
					<td class="py-4 text-yellow-500 font-bold">{invitation.permission}</td>
					{#if isAdmin(team.permissions[0].permission)}
						<td class="text-center py-4 flex-col space-y-2">
							<button
								class="bg-red-600 hover:bg-red-500 w-52"
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
	<div class="max-w-2xl mx-auto pt-8">
		<form on:submit|preventDefault={sendInvitation}>
			<div class="font-bold flex space-x-1 py-5 px-6">
				<div class="text-xl tracking-tight mr-4">Invite new member</div>
				<div class="text-center">
					<button class="bg-green-600 hover:bg-green-500" type="submit">Send invitation</button>
				</div>
			</div>
			<div class="px-4 sm:px-6 flex-col space-y-2">
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
