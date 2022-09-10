<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/applications`);
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
	export let applications: Array<any> = [];
	let ownApplications: Array<any> = [];
	let otherApplications: Array<any> = [];
	import { get, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { t } from '$lib/translations';
	import { getDomain } from '$lib/common';
	import { appSession } from '$lib/store';
	import ApplicationsIcons from '$lib/components/svg/applications/ApplicationIcons.svelte';

	ownApplications = applications.filter((application) => {
		if (application.teams[0].id === $appSession.teamId) {
			return application;
		}
	});
	otherApplications = applications.filter((application) => {
		if (application.teams[0].id !== $appSession.teamId) {
			return application;
		}
	});
	async function newApplication() {
		const { id } = await post('/applications/new', {});
		return await goto(`/applications/${id}`, { replaceState: true });
	}
</script>

<nav class="header">
	<h1 class="mr-4 text-2xl font-bold">{$t('index.applications')}</h1>
	{#if $appSession.isAdmin}
		<button on:click={newApplication} class="btn btn-square btn-sm bg-applications">
			<svg
				class="h-6 w-6"
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
		</button>
	{/if}
</nav>
<br />
<div class="flex flex-col justify-center mt-10 pb-12 lg:pt-16 sm:pb-16">
	{#if !applications || ownApplications.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('application.no_applications_found')}</div>
		</div>
	{/if}
	{#if ownApplications.length > 0 || otherApplications.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownApplications as application}
					<a href="/applications/{application.id}" class="p-2 no-underline">
						<div class="box-selection group relative hover:bg-green-600">
							{#if application.buildPack}
								<ApplicationsIcons {application} />
							{/if}

							<div class="truncate text-center text-xl font-bold">{application.name}</div>
							{#if $appSession.teamId === '0' && otherApplications.length > 0}
								<div class="truncate text-center">Team {application.teams[0].name}</div>
							{/if}
							{#if application.fqdn}
								<div class="truncate text-center">{getDomain(application.fqdn) || ''}</div>
							{/if}
							{#if application.settings.isBot}
								<div class="truncate text-center">BOT</div>
							{/if}
							{#if application.destinationDocker?.name}
								<div class="truncate text-center">{application.destinationDocker.name}</div>
							{/if}
							{#if !application.gitSourceId || !application.repository || !application.branch}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									Git Source Missing
								</div>
							{:else if !application.destinationDockerId}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									Destination Missing
								</div>
							{:else if !application.fqdn && !application.settings.isBot}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									URL Missing
								</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>
			{#if otherApplications.length > 0 && $appSession.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-2xl font-bold text-center">Other Applications</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherApplications as application}
						<a href="/applications/{application.id}" class="p-2 no-underline">
							<div class="box-selection group relative hover:bg-green-600">
								{#if application.buildPack}
									<ApplicationsIcons {application} />
								{/if}

								<div class="truncate text-center text-xl font-bold">{application.name}</div>
								{#if $appSession.teamId === '0'}
									<div class="truncate text-center">Team {application.teams[0].name}</div>
								{/if}
								{#if application.fqdn}
									<div class="truncate text-center">{getDomain(application.fqdn) || ''}</div>
								{/if}
								{#if !application.gitSourceId || !application.destinationDockerId || !application.fqdn}
									<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
