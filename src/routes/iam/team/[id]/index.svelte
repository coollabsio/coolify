<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		const url = `/iam/team/${params.id}.json`;
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
	export let invitations: any[];
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
	// let myPermission = permissions.find((u) => u.user.id === $session.userId).permission;
	function isAdmin(permission: string) {
		if (permission === 'admin' || permission === 'owner') {
			return true;
		}

		return false;
	}

	async function sendInvitation() {
		try {
			await post(`/iam/team/${id}/invitation/invite.json`, {
				teamId: team.id,
				teamName: invitation.teamName,
				email: invitation.email.toLowerCase(),
				permission: invitation.permission
			});
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function revokeInvitation(id: string) {
		try {
			await post(`/iam/team/${id}/invitation/revoke.json`, { id });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function removeFromTeam(uid: string) {
		try {
			await post(`/iam/team/${id}/remove/user.json`, { teamId: team.id, uid });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function changePermission(userId: string, permissionId: string, currentPermission: string) {
		let newPermission = 'read';
		if (currentPermission === 'read') {
			newPermission = 'admin';
		}
		try {
			await post(`/iam/team/${id}/permission/change.json`, { userId, newPermission, permissionId });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/iam/team/${id}.json`, { ...team });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 px-6 text-2xl font-bold">
	<div class="tracking-tight">Team</div>
	<span class="arrow-right-applications px-1 text-cyan-500">></span>
	<span class="pr-2">{team.name}</span>
</div>
<div class="mx-auto max-w-4xl px-6">
	<form on:submit|preventDefault={handleSubmit} class=" py-4">
		<div class="flex space-x-1 pb-5">
			<div class="title font-bold">Settings</div>
			<button class="bg-cyan-600 hover:bg-cyan-500" type="submit">Save</button>
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="mt-2 grid grid-cols-2">
				<div class="flex-col">
					<label for="name" class="text-base font-bold text-stone-100">Name</label>
					{#if team.id === '0'}
						<Explainer
							customClass="w-full"
							text="This is the <span class='text-red-500 font-bold'>root</span> team. That means members of this group can manage instance wide settings and have all the priviliges in Coolify (imagine like root user on Linux)."
						/>
					{/if}
				</div>
				<input id="name" name="name" placeholder="name" bind:value={team.name} />
			</div>
		</div>
	</form>

	<div class="flex space-x-1 py-5 pt-10 font-bold">
		<div class="title">Members</div>
	</div>
	<div class="px-4 sm:px-6">
		<table class="w-full border-separate text-left">
			<thead>
				<tr class="h-8 border-b border-coolgray-400">
					<th scope="col">Email</th>
					<th scope="col">Permission</th>
					<th scope="col" class="text-center">Actions</th>
				</tr>
			</thead>
			{#each permissions as permission}
				<tr class="text-xs">
					<td class="py-4"
						>{permission.user.email}
						<span class="font-bold">{permission.user.id === $session.userId ? '(You)' : ''}</span
						></td
					>
					<td class="py-4">{permission.permission}</td>
					{#if $session.isAdmin && permission.user.id !== $session.userId && permission.permission !== 'owner'}
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
	{#if $session.isAdmin}
		<form on:submit|preventDefault={sendInvitation} class="py-5 pt-10">
			<div class="flex space-x-1">
				<div class="flex space-x-1">
					<div class="title font-bold">Invite new member</div>
					<button class="bg-cyan-600 hover:bg-cyan-500" type="submit">Send invitation</button>
				</div>
			</div>
			<Explainer
				text="You can only invite registered users at the moment - will be extended soon."
			/>
			<div class="flex-col space-y-2 px-4 pt-5 sm:px-6">
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
						class="rounded-none rounded-l border border-dashed border-transparent"
						type="button"
						class:border-coolgray-300={invitation.permission !== 'read'}
						class:bg-pink-500={invitation.permission === 'read'}>Read</button
					>
					<button
						on:click={() => (invitation.permission = 'admin')}
						class="rounded-none rounded-r border border-dashed border-transparent"
						type="button"
						class:border-coolgray-300={invitation.permission !== 'admin'}
						class:bg-red-500={invitation.permission === 'admin'}>Admin</button
					>
				</div>
			</div>
		</form>
	{/if}
</div>
