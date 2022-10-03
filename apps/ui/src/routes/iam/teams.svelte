<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/iam/teams`);
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
	export let allTeams: any;
	export let ownTeams: any;
	import { get, post } from '$lib/api';
	import Cookies from 'js-cookie';
	import { appSession } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { goto } from '$app/navigation';

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
		return await goto(`/iam/teams/${id}`, { replaceState: true });
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 items-center  pb-3">
			<div class="title font-bold">Teams</div>
			<button on:click={newTeam} class="btn btn-sm btn-primary"> Add New Team </button>
		</div>
	</div>
</div>
<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-2 px-6">
	{#each ownTeams as team}
		<a href="/iam/teams/{team.id}" class="p-2 no-underline">
			<div
				class="flex flex-col w-full rounded p-5 bg-coolgray-200 hover:bg-coolgray-300 indicator duration-150 h-36"
			>
				<div>
					<div class="truncate text-center text-xl font-bold">
						{team.name}
						{#if $appSession.teamId === team.id}
							<button class="badge bg-applications text-white font-bold rounded">Active Team</button
							>
						{/if}
					</div>
					<div class="mt-1 text-center text-xs">
						{team.permissions?.length} member(s)
					</div>
				</div>
				<div class="flex items-center justify-center pt-3">
					{#if $appSession.teamId !== team.id}
						<button
							on:click|preventDefault={() => switchTeam(team.id)}
							class="btn btn-sm btn-primary">Switch to this team</button
						>
					{/if}
				</div>
			</div>
		</a>
	{/each}
</div>
<div class="divider w-32 mx-auto" />
<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 px-6">
	{#if $appSession.teamId === '0' && allTeams.length > 0}
		{#each allTeams as team}
			<a href="/iam/teams/{team.id}" class="p-2 no-underline">
				<div
					class="flex flex-col w-full rounded p-5 bg-coolgray-200 hover:bg-coolgray-300 indicator duration-150 relative"
				>
					<div class="truncate text-center text-xl font-bold">
						{team.name}
					</div>

					<div class="mt-1 text-center text-xs">{team.permissions?.length} member(s)</div>
				</div>
			</a>
		{/each}
	{/if}
</div>
