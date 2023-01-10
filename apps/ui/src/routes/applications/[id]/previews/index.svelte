<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			return {
				props: {
					application: stuff.application
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let application: any;
	import { get, post } from '$lib/api';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { asyncSleep, errorNotification, getRndInteger } from '$lib/common';
	import { onDestroy, onMount } from 'svelte';
	import { addToast, appSession } from '$lib/store';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';

	const { id } = $page.params;
	let loadBuildingStatusInterval: any = null;
	let loading = {
		init: true,
		restart: false,
		removing: false
	};
	let numberOfGetStatus = 0;
	let status: any = {};

	async function removeApplication(preview: any) {
		try {
			loading.removing = true;
			await post(`/applications/${id}/stop/preview`, {
				pullmergeRequestId: preview.pullmergeRequestId
			});
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function redeploy(preview: any) {
		try {
			const { buildId } = await post(`/applications/${id}/deploy`, {
				pullmergeRequestId: preview.pullmergeRequestId,
				branch: preview.sourceBranch
			});
			addToast({
				message: 'Deployment queued.',
				type: 'success'
			});
			if ($page.url.pathname.startsWith(`/applications/${id}/logs/build`)) {
				return window.location.assign(`/applications/${id}/logs/build?buildId=${buildId}`);
			} else {
				return await goto(`/applications/${id}/logs/build?buildId=${buildId}`, {
					replaceState: true
				});
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function loadPreviewsFromDocker() {
		try {
			const { previews } = await post(`/applications/${id}/previews/load`, {});
			addToast({
				message: 'Previews loaded.',
				type: 'success'
			});
			application.previewApplication = previews;
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function getStatus(resources: any) {
		const { applicationId, pullmergeRequestId, id } = resources;
		if (status[id]) return status[id];
		while (numberOfGetStatus > 1) {
			await asyncSleep(getRndInteger(100, 200));
		}
		try {
			numberOfGetStatus++;
			let isRunning = false;
			let isBuilding = false;
			const response = await get(
				`/applications/${applicationId}/previews/${pullmergeRequestId}/status`
			);
			isRunning = response.isRunning;
			isBuilding = response.isBuilding;
			if (isBuilding) {
				status[id] = 'building';
				return 'building';
			} else if (isRunning) {
				status[id] = 'running';
				return 'running';
			} else {
				status[id] = 'stopped';
				return 'stopped';
			}
		} catch (error) {
			status[id] = 'error';
			return 'error';
		} finally {
			numberOfGetStatus--;
			status = status;
		}
	}
	async function restartPreview(preview: any) {
		try {
			loading.restart = true;
			const { pullmergeRequestId } = preview;
			await post(`/applications/${id}/previews/${pullmergeRequestId}/restart`, {});
			addToast({
				type: 'success',
				message: 'Restart successful.'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			await getStatus(preview);
			loading.restart = false;
		}
	}
	onDestroy(() => {
		clearInterval(loadBuildingStatusInterval);
	});
	onMount(async () => {
		loadBuildingStatusInterval = setInterval(() => {
			application.previewApplication.forEach(async (preview: any) => {
				const { applicationId, pullmergeRequestId } = preview;
				if (status[preview.id] === 'building') {
					const response = await get(
						`/applications/${applicationId}/previews/${pullmergeRequestId}/status`
					);
					if (response.isBuilding) {
						status[preview.id] = 'building';
					} else if (response.isRunning) {
						status[preview.id] = 'running';
						return 'running';
					} else {
						status[preview.id] = 'stopped';
						return 'stopped';
					}
				}
			});
		}, 2000);
		try {
			loading.init = true;
			loading.restart = true;
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.init = false;
			loading.restart = false;
		}
	});
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6  space-x-2">
			<div class="title font-bold pb-3">Preview Deployments</div>
			<div class="text-center">
				<button class="btn btn-sm bg-coollabs" on:click={loadPreviewsFromDocker}
					>Load Previews</button
				>
			</div>
		</div>
	</div>
</div>

{#if loading.init}
	<div class="px-6 pt-4">
		<div class="flex justify-center py-4 text-center text-xl font-bold">Loading...</div>
	</div>
{:else if application.previewApplication.length > 0}
	<div class="grid grid-col gap-4 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-2 px-6">
		{#each application.previewApplication as preview}
			<div class="no-underline mb-5 w-full">
				<div class="w-full rounded p-5 bg-coolgray-200 indicator">
					{#await getStatus(preview)}
						<span class="indicator-item badge bg-yellow-500 badge-sm" />
					{:then}
						{#if status[preview.id] === 'running'}
							<span class="indicator-item badge bg-success badge-sm" />
						{:else}
							<span class="indicator-item badge bg-error badge-sm" />
						{/if}
					{/await}
					<div class="w-full flex flex-row">
						<div class="w-full flex flex-col">
							<h1 class="font-bold text-lg lg:text-xl truncate">
								PR #{preview.pullmergeRequestId}
								{#if status[preview.id] === 'building'}
									<span
										class="badge badge-sm text-xs uppercase rounded bg-coolgray-300 text-green-500 border-none font-bold"
									>
										BUILDING
									</span>
								{/if}
							</h1>
							<div class="h-10 text-xs">
								<h2>{preview.customDomain.replace('https://', '').replace('http://', '')}</h2>
							</div>

							<div class="flex justify-end items-end space-x-2 h-10">
								{#if preview.customDomain}
									<a
										id="openpreview"
										href={preview.customDomain}
										target="_blank noreferrer"
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
								<Tooltip triggeredBy="#openpreview">Open Preview</Tooltip>
								{#if loading.restart}
									<button
										class="icons flex animate-spin items-center space-x-2 bg-transparent text-sm duration-500 ease-in-out hover:bg-transparent"
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
											<path d="M9 4.55a8 8 0 0 1 6 14.9m0 -4.45v5h5" />
											<line x1="5.63" y1="7.16" x2="5.63" y2="7.17" />
											<line x1="4.06" y1="11" x2="4.06" y2="11.01" />
											<line x1="4.63" y1="15.1" x2="4.63" y2="15.11" />
											<line x1="7.16" y1="18.37" x2="7.16" y2="18.38" />
											<line x1="11" y1="19.94" x2="11" y2="19.95" />
										</svg>
									</button>
								{:else}
									<button
										id="restart"
										disabled={!$appSession.isAdmin}
										on:click={() => restartPreview(preview)}
										type="submit"
										class="icons bg-transparent text-sm flex items-center space-x-2"
									>
										<svg
											xmlns="http://www.w3.org/2000/svg"
											class="w-6 h-6"
											viewBox="0 0 24 24"
											stroke-width="1.5"
											stroke="currentColor"
											fill="none"
											stroke-linecap="round"
											stroke-linejoin="round"
										>
											<path stroke="none" d="M0 0h24v24H0z" fill="none" />
											<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
											<path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
										</svg>
									</button>
								{/if}

								<Tooltip triggeredBy="#restart">Restart (useful to change secrets)</Tooltip>
								<button
									id="forceredeploypreview"
									class="icons"
									disabled={!$appSession.isAdmin}
									on:click={() => redeploy(preview)}
								>
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="w-6 h-6"
										viewBox="0 0 24 24"
										stroke-width="1.5"
										stroke="currentColor"
										fill="none"
										stroke-linecap="round"
										stroke-linejoin="round"
									>
										<path stroke="none" d="M0 0h24v24H0z" fill="none" />
										<path
											d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
											transform="rotate(-45 12 12)"
										/>
									</svg></button
								>
								<Tooltip triggeredBy="#forceredeploypreview">Force redeploy (without cache)</Tooltip
								>
								<button
									id="deletepreview"
									class="icons"
									class:hover:text-error={!loading.removing}
									disabled={loading.removing || !$appSession.isAdmin}
									on:click={() => removeApplication(preview)}
									><DeleteIcon />
								</button>
								<Tooltip triggeredBy="#deletepreview">Delete Preview</Tooltip>
							</div>
						</div>
					</div>
				</div>
			</div>
		{/each}
	</div>
{:else}
	No previews found.
{/if}
