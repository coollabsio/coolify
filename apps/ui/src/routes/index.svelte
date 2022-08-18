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

	let loading = {
		cleanup: false
	};

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
	async function manuallyCleanupStorage() {
		try {
			loading.cleanup = true;
			await post('/internal/cleanup', {});
			return addToast({
				message: 'Cleanup done.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.cleanup = false;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.dashboard')}</div>
	<button on:click={manuallyCleanupStorage} class:loading={loading.cleanup} class="btn btn-sm"
		>Cleanup Storage</button
	>
</div>
<div class="mt-10 pb-12 tracking-tight sm:pb-16">
	<div class="mx-auto px-10">
		<div class="flex flex-col justify-center xl:flex-row">
			{#if applications.length > 0}
				<div>
					<div class="title">Resources</div>
					<div class="flex items-start justify-center p-8">
						<table class="rounded-none text-base">
							<tbody>
								{#each applications as application}
									<tr>
										<td class="space-x-2 items-center tracking-tight font-bold">
											{#await getStatus(application)}
												<div class="inline-flex w-2 h-2 bg-yellow-500 rounded-full" />
											{:then status}
												{#if status === 'Running'}
													<div class="inline-flex w-2 h-2 bg-success rounded-full" />
												{:else}
													<div class="inline-flex w-2 h-2 bg-error rounded-full" />
												{/if}
											{/await}
											<div class="inline-flex">{application.name}</div>
										</td>
										<td class="px-10 inline-flex">
											<ApplicationsIcons {application} isAbsolute={false} />
										</td>
										<td class="px-10">
											<div
												class="badge badge-outline text-xs border-applications rounded text-white"
											>
												Application
												{#if application.settings.isBot}
													| BOT
												{/if}
											</div></td
										>
										<td class="flex justify-end">
											{#if application.fqdn}
												<a
													href={application.fqdn}
													target="_blank"
													class="icons bg-transparent text-sm inline-flex"
													><svg
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
													</svg></a
												>
											{/if}
											{#if application.settings.isBot && application.exposePort}
												<a
													href={`http://${dev ? 'localhost' : settings.ipv4}:${
														application.exposePort
													}`}
													target="_blank"
													class="icons bg-transparent text-sm inline-flex"
													><svg
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
													</svg></a
												>
											{/if}
											<a
												href={`/applications/${application.id}`}
												class="icons bg-transparent text-sm inline-flex"
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
													<rect x="4" y="8" width="4" height="4" />
													<line x1="6" y1="4" x2="6" y2="8" />
													<line x1="6" y1="12" x2="6" y2="20" />
													<rect x="10" y="14" width="4" height="4" />
													<line x1="12" y1="4" x2="12" y2="14" />
													<line x1="12" y1="18" x2="12" y2="20" />
													<rect x="16" y="5" width="4" height="4" />
													<line x1="18" y1="4" x2="18" y2="5" />
													<line x1="18" y1="9" x2="18" y2="20" />
												</svg>
											</a>
										</td>
									</tr>
								{/each}

								{#each services as service}
									<tr>
										<td class="space-x-2 items-center tracking-tight font-bold">
											{#await getStatus(service)}
												<div class="inline-flex w-2 h-2 bg-yellow-500 rounded-full" />
											{:then status}
												{#if status === 'Running'}
													<div class="inline-flex w-2 h-2 bg-success rounded-full" />
												{:else}
													<div class="inline-flex w-2 h-2 bg-error rounded-full" />
												{/if}
											{/await}
											<div class="inline-flex">{service.name}</div>
										</td>
										<td class="px-10 inline-flex">
											<ServiceIcons type={service.type} isAbsolute={false} />
										</td>
										<td class="px-10"
											><div class="badge badge-outline text-xs border-services rounded text-white">
												Service
											</div>
										</td>

										<td class="flex justify-end">
											{#if service.fqdn}
												<a
													href={service.fqdn}
													target="_blank"
													class="icons bg-transparent text-sm inline-flex"
													><svg
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
													</svg></a
												>
											{/if}
											<a
												href={`/services/${service.id}`}
												class="icons bg-transparent text-sm inline-flex"
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
													<rect x="4" y="8" width="4" height="4" />
													<line x1="6" y1="4" x2="6" y2="8" />
													<line x1="6" y1="12" x2="6" y2="20" />
													<rect x="10" y="14" width="4" height="4" />
													<line x1="12" y1="4" x2="12" y2="14" />
													<line x1="12" y1="18" x2="12" y2="20" />
													<rect x="16" y="5" width="4" height="4" />
													<line x1="18" y1="4" x2="18" y2="5" />
													<line x1="18" y1="9" x2="18" y2="20" />
												</svg>
											</a>
										</td>
									</tr>
								{/each}
								{#each databases as database}
									<tr>
										<td class="space-x-2 items-center tracking-tight font-bold">
											{#await getStatus(database)}
												<div class="inline-flex w-2 h-2 bg-yellow-500 rounded-full" />
											{:then status}
												{#if status === 'Running'}
													<div class="inline-flex w-2 h-2 bg-success rounded-full" />
												{:else}
													<div class="inline-flex w-2 h-2 bg-error rounded-full" />
												{/if}
											{/await}
											<div class="inline-flex">{database.name}</div>
										</td>
										<td class="px-10 inline-flex">
											<DatabaseIcons type={database.type} />
										</td>
										<td class="px-10">
											<div class="badge badge-outline text-xs border-databases rounded text-white">
												Database
											</div>
										</td>
										<td class="flex justify-end">
											<a
												href={`/databases/${database.id}`}
												class="icons bg-transparent text-sm inline-flex ml-11"
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
													<rect x="4" y="8" width="4" height="4" />
													<line x1="6" y1="4" x2="6" y2="8" />
													<line x1="6" y1="12" x2="6" y2="20" />
													<rect x="10" y="14" width="4" height="4" />
													<line x1="12" y1="4" x2="12" y2="14" />
													<line x1="12" y1="18" x2="12" y2="20" />
													<rect x="16" y="5" width="4" height="4" />
													<line x1="18" y1="4" x2="18" y2="5" />
													<line x1="18" y1="9" x2="18" y2="20" />
												</svg>
											</a>
										</td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</div>
			{/if}
			{#if $appSession.teamId === '0'}
				<Usage />
			{/if}
		</div>
	</div>
</div>
