<script lang="ts">
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	if ($appSession.pendingInvitations.length === 0) {
		goto('/iam/teams');
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
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 items-center">
			<div class="title font-bold pb-3">Pending Invitations</div>
		</div>
	</div>
</div>

<div class="w-full  grid gap-2">
	<div class="flex flex-col pb-2 space-y-4 lg:space-y-2">
		{#each $appSession.pendingInvitations as invitation}
			<div class="flex flex-col justify-center items-center">
				<div class="text-xl pb-4 text-center">
					Invited to <span class="font-bold text-pink-500">{invitation.teamName}</span> with
					<span class="font-bold text-red-500">{invitation.permission}</span> permission.
				</div>
				<div class=" flex space-x-2">
					<button
						class="btn btn-primary"
						on:click={() => acceptInvitation(invitation.id, invitation.teamId)}>Accept</button
					>
					<button class="btn" on:click={() => revokeInvitation(invitation.id, invitation.teamId)}
						>Ignore</button
					>
				</div>
			</div>
		{/each}
	</div>
</div>
