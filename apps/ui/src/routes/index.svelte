<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';

	export const load: Load = async ({}) => {
		try {
			const data = await get('/resources');
			return {
				props: {
					...data
				},
				stuff: {
					...data
				}
			};
		} catch (error) {
			console.log(error);
			return {};
		}
	};
</script>

<script lang="ts">
	export let applications: any;
	export let databases: any;
	export let services: any;
	export let settings: any;

	import { get, post } from '$lib/api';
	import Usage from '$lib/components/Usage.svelte';
	import { t } from '$lib/translations';
	import { errorNotification, asyncSleep } from '$lib/common';
	import { addToast, appSession } from '$lib/store';

	import ApplicationsIcons from '$lib/components/svg/applications/ApplicationIcons.svelte';
	import DatabaseIcons from '$lib/components/svg/databases/DatabaseIcons.svelte';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';
	import { dev } from '$app/env';

	let numberOfGetStatus = 0;

	function getRndInteger(min: number, max: number) {
		return Math.floor(Math.random() * (max - min + 1)) + min;
	}

	async function getStatus(resources: any) {
		while (numberOfGetStatus > 1) {
			await asyncSleep(getRndInteger(100, 200));
		}
		try {
			numberOfGetStatus++;
			const { id, buildPack, dualCerts } = resources;
			let isRunning = false;
			if (buildPack) {
				const response = await get(`/applications/${id}/status`);
				isRunning = response.isRunning;
			} else if (typeof dualCerts !== 'undefined') {
				const response = await get(`/services/${id}/status`);
				isRunning = response.isRunning;
			} else {
				const response = await get(`/databases/${id}/status`);
				isRunning = response.isRunning;
			}
			if (isRunning) {
				return 'Running';
			} else {
				return 'Stopped';
			}
		} catch (error) {
			return 'Error';
		} finally {
			numberOfGetStatus--;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.dashboard')}</div>
</div>
<div class="container lg:mx-auto lg:p-0 p-5">
	{#if $appSession.teamId === '0'}
		<Usage />
	{/if}
	{#if applications.length > 0}
		<h1 class="title text-4xl mt-10">Applications</h1>
		<div class="divider" />
		<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 lg:grid-cols-3">
			{#each applications as application}
				<a class="no-underline mb-5" href={`/applications/${application.id}`}>
					<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-coolgray-300 indicator">
						{#await getStatus(application)}
							<span class="indicator-item badge bg-yellow-500 badge-xs" />
						{:then status}
							{#if status === 'Running'}
								<span class="indicator-item badge bg-success badge-xs" />
							{:else}
								<span class="indicator-item badge bg-error badge-xs" />
							{/if}
						{/await}
						<div class="w-full flex flex-row">
							<ApplicationsIcons {application} isAbsolute={false} />
							<div class="w-full flex flex-col ml-5">
								<span>
									Application
									{#if application.settings.isBot}
										| BOT
									{/if}
								</span>
								<h1 class="font-bold text-lg">{application.name}</h1>
								<div class="divider" />
								<div class="flex justify-end space-x-2">
									{#if application.fqdn}
										<a href={application.fqdn} target="_blank" class="icons">
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-6 w-6"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
												<line x1="10" y1="14" x2="20" y2="4" />
												<polyline points="15 4 20 4 20 9" />
											</svg>
										</a>
									{/if}
									{#if application.settings.isBot && application.exposePort}
										<a
											href={`http://${dev ? 'localhost' : settings.ipv4}:${application.exposePort}`}
											target="_blank"
											class="icons"
										>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-6 w-6"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
												<line x1="10" y1="14" x2="20" y2="4" />
												<polyline points="15 4 20 4 20 9" />
											</svg>
										</a>
									{/if}
								</div>
							</div>
						</div>
					</div>
				</a>
			{/each}
		</div>
		<h1 class="title text-4xl mt-10">Services</h1>
		<div class="divider" />
		<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 lg:grid-cols-3">
			{#each services as service}
				<a class="no-underline mb-5" href={`/services/${service.id}`}>
					<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-coolgray-300 indicator">
						{#await getStatus(service)}
							<span class="indicator-item badge bg-yellow-500 badge-xs" />
						{:then status}
							{#if status === 'Running'}
								<span class="indicator-item badge bg-success badge-xs" />
							{:else}
								<span class="indicator-item badge bg-error badge-xs" />
							{/if}
						{/await}
						<div class="w-full flex flex-row">
							<ServiceIcons type={service.type} isAbsolute={false} />
							<div class="w-full flex flex-col ml-5">
								<span> Service </span>
								<h1 class="font-bold text-lg">{service.name}</h1>
								<div class="divider" />
								<div class="flex justify-end space-x-2">
									{#if service.fqdn}
										<a href={service.fqdn} target="_blank" class="icons">
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-6 w-6"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
												<line x1="10" y1="14" x2="20" y2="4" />
												<polyline points="15 4 20 4 20 9" />
											</svg>
										</a>
									{/if}
								</div>
							</div>
						</div>
					</div>
				</a>
			{/each}
		</div>
		<h1 class="title text-4xl mt-10">Databases</h1>
		<div class="divider" />
		<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 lg:grid-cols-3">
			{#each databases as database}
				<a class="no-underline mb-5" href={`/databases/${database.id}`}>
					<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-coolgray-300 indicator">
						{#await getStatus(database)}
							<span class="indicator-item badge bg-yellow-500 badge-xs" />
						{:then status}
							{#if status === 'Running'}
								<span class="indicator-item badge bg-success badge-xs" />
							{:else}
								<span class="indicator-item badge bg-error badge-xs" />
							{/if}
						{/await}
						<div class="w-full flex flex-row">
							<DatabaseIcons type={database.type} isAbsolute={false} />
							<div class="w-full flex flex-col ml-5">
								<span> Service </span>
								<h1 class="font-bold text-lg">{database.name}</h1>
								<div class="divider" />
							</div>
						</div>
					</div>
				</a>
			{/each}
		</div>
	{:else if $appSession.teamId !== '0'}
		<div class="text-center text-xl font-bold h-screen w-full flex flex-col justify-center">
			<h1 class="text-5xl">Nothing is configured yet.</h1>
		</div>
	{/if}
</div>
