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
	import Secret from '../_Secret.svelte';
	import { get, post } from '$lib/api';
	import { page } from '$app/stores';
	import { t } from '$lib/translations';
	import { goto } from '$app/navigation';
	import { asyncSleep, errorNotification, getDomain, getRndInteger } from '$lib/common';
	import { onDestroy, onMount } from 'svelte';
	import { addToast } from '$lib/store';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';

	const { id } = $page.params;
	let loadBuildingStatusInterval: any = null;
	let PRMRSecrets: any;
	let applicationSecrets: any;
	let loading = {
		init: true,
		restart: false,
		removing: false
	};
	let numberOfGetStatus = 0;
	let status: any = {};
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets`);
		PRMRSecrets = [...data.secrets];
	}
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
				message: 'Deployment queued',
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
			application.previewApplication.forEach((preview: any) => {
				if (status[id] === 'building') {
					await getStatus(preview);
				}
			});
		}, 3000);
		try {
			loading.init = true;
			loading.restart = true;
			const response = await get(`/applications/${id}/previews`);
			PRMRSecrets = response.PRMRSecrets;
			applicationSecrets = response.applicationSecrets;
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.init = false;
			loading.restart = false;
		}
	});
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Preview Deployments
		</div>
		<span class="text-xs">{application?.name}</span>
	</div>
	{#if application.gitSource?.htmlUrl && application.repository && application.branch}
		<a
			href="{application.gitSource.htmlUrl}/{application.repository}/tree/{application.branch}"
			target="_blank"
			class="w-10"
		>
			{#if application.gitSource?.type === 'gitlab'}
				<svg viewBox="0 0 128 128" class="icons">
					<path
						fill="#FC6D26"
						d="M126.615 72.31l-7.034-21.647L105.64 7.76c-.716-2.206-3.84-2.206-4.556 0l-13.94 42.903H40.856L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664 1.385 72.31a4.792 4.792 0 001.74 5.358L64 121.894l60.874-44.227a4.793 4.793 0 001.74-5.357"
					/><path fill="#E24329" d="M64 121.894l23.144-71.23H40.856L64 121.893z" /><path
						fill="#FC6D26"
						d="M64 121.894l-23.144-71.23H8.42L64 121.893z"
					/><path
						fill="#FCA326"
						d="M8.42 50.663L1.384 72.31a4.79 4.79 0 001.74 5.357L64 121.894 8.42 50.664z"
					/><path
						fill="#E24329"
						d="M8.42 50.663h32.436L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664z"
					/><path fill="#FC6D26" d="M64 121.894l23.144-71.23h32.437L64 121.893z" /><path
						fill="#FCA326"
						d="M119.58 50.663l7.035 21.647a4.79 4.79 0 01-1.74 5.357L64 121.894l55.58-71.23z"
					/><path
						fill="#E24329"
						d="M119.58 50.663H87.145l13.94-42.902c.717-2.206 3.84-2.206 4.557 0l13.94 42.903z"
					/>
				</svg>
			{:else if application.gitSource?.type === 'github'}
				<svg viewBox="0 0 128 128" class="icons">
					<g fill="#ffffff"
						><path
							fill-rule="evenodd"
							clip-rule="evenodd"
							d="M64 5.103c-33.347 0-60.388 27.035-60.388 60.388 0 26.682 17.303 49.317 41.297 57.303 3.017.56 4.125-1.31 4.125-2.905 0-1.44-.056-6.197-.082-11.243-16.8 3.653-20.345-7.125-20.345-7.125-2.747-6.98-6.705-8.836-6.705-8.836-5.48-3.748.413-3.67.413-3.67 6.063.425 9.257 6.223 9.257 6.223 5.386 9.23 14.127 6.562 17.573 5.02.542-3.903 2.107-6.568 3.834-8.076-13.413-1.525-27.514-6.704-27.514-29.843 0-6.593 2.36-11.98 6.223-16.21-.628-1.52-2.695-7.662.584-15.98 0 0 5.07-1.623 16.61 6.19C53.7 35 58.867 34.327 64 34.304c5.13.023 10.3.694 15.127 2.033 11.526-7.813 16.59-6.19 16.59-6.19 3.287 8.317 1.22 14.46.593 15.98 3.872 4.23 6.215 9.617 6.215 16.21 0 23.194-14.127 28.3-27.574 29.796 2.167 1.874 4.097 5.55 4.097 11.183 0 8.08-.07 14.583-.07 16.572 0 1.607 1.088 3.49 4.148 2.897 23.98-7.994 41.263-30.622 41.263-57.294C124.388 32.14 97.35 5.104 64 5.104z"
						/><path
							d="M26.484 91.806c-.133.3-.605.39-1.035.185-.44-.196-.685-.605-.543-.906.13-.31.603-.395 1.04-.188.44.197.69.61.537.91zm2.446 2.729c-.287.267-.85.143-1.232-.28-.396-.42-.47-.983-.177-1.254.298-.266.844-.14 1.24.28.394.426.472.984.17 1.255zM31.312 98.012c-.37.258-.976.017-1.35-.52-.37-.538-.37-1.183.01-1.44.373-.258.97-.025 1.35.507.368.545.368 1.19-.01 1.452zm3.261 3.361c-.33.365-1.036.267-1.552-.23-.527-.487-.674-1.18-.343-1.544.336-.366 1.045-.264 1.564.23.527.486.686 1.18.333 1.543zm4.5 1.951c-.147.473-.825.688-1.51.486-.683-.207-1.13-.76-.99-1.238.14-.477.823-.7 1.512-.485.683.206 1.13.756.988 1.237zm4.943.361c.017.498-.563.91-1.28.92-.723.017-1.308-.387-1.315-.877 0-.503.568-.91 1.29-.924.717-.013 1.306.387 1.306.88zm4.598-.782c.086.485-.413.984-1.126 1.117-.7.13-1.35-.172-1.44-.653-.086-.498.422-.997 1.122-1.126.714-.123 1.354.17 1.444.663zm0 0"
						/></g
					>
				</svg>
			{/if}
		</a>
	{/if}
</div>
{#if loading.init}
	<div class="mx-auto max-w-6xl px-6 pt-4">
		<div class="flex justify-center py-4 text-center text-xl font-bold">Loading...</div>
	</div>
{:else}
	<div class="mx-auto max-w-6xl px-6 pt-4">
		<div class="flex justify-center py-4 text-center">
			<SimpleExplainer
				customClass="w-full"
				text={applicationSecrets.length === 0
					? "You can add secrets to PR/MR deployments. Please add secrets to the application first. <br>Useful for creating <span class='text-green-500 font-bold'>staging</span> environments."
					: "These values overwrite application secrets in PR/MR deployments. <br>Useful for creating <span class='text-green-500 font-bold'>staging</span> environments."}
			/>
		</div>
		<div class="text-center">
			<SimpleExplainer
				customClass="w-full"
				text={'If your preview is not shown, try load them directly from Docker Engine.<br>(Changed previews process flow in <span class="font-bold text-white">v3.10.4</span>)'}
			/>
			<button class="btn btn-sm bg-coollabs" on:click={loadPreviewsFromDocker}
				>Fetch Previews</button
			>
		</div>
		{#if applicationSecrets.length !== 0}
			<table class="mx-auto border-separate text-left">
				<thead>
					<tr class="h-12">
						<th scope="col">{$t('forms.name')}</th>
						<th scope="col">{$t('forms.value')}</th>
						<th scope="col" class="w-64 text-center"
							>{$t('application.preview.need_during_buildtime')}</th
						>
						<th scope="col" class="w-96 text-center">{$t('forms.action')}</th>
					</tr>
				</thead>
				<tbody>
					{#each applicationSecrets as secret}
						{#key secret.id}
							<tr>
								<Secret
									PRMRSecret={PRMRSecrets.find((s) => s.name === secret.name)}
									isPRMRSecret
									name={secret.name}
									value={secret.value}
									isBuildSecret={secret.isBuildSecret}
									on:refresh={refreshSecrets}
								/>
							</tr>
						{/key}
					{/each}
				</tbody>
			</table>
		{/if}
	</div>

	<div class="container lg:mx-auto lg:p-0 px-8 p-5 lg:pt-10">
		{#if application.previewApplication.length > 0}
			<div
				class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 p-4"
			>
				{#each application.previewApplication as preview}
					<div class="no-underline mb-5">
						<div class="w-full rounded p-5 bg-coolgray-200 indicator">
							{#await getStatus(preview)}
								<span class="indicator-item badge bg-yellow-500 badge-sm" />
							{:then status}
								{#if status === 'running'}
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
											<a id="openpreview" href={preview.customDomain} target="_blank" class="icons">
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
										<div class="border border-coolgray-500 h-8" />
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
										<Tooltip triggeredBy="#forceredeploypreview"
											>Force redeploy (without cache)</Tooltip
										>
										<div class="border border-coolgray-500 h-8" />
										<button
											id="deletepreview"
											class="icons"
											class:hover:text-error={!loading.removing}
											disabled={loading.removing}
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
			<div class="flex-col">
				<div class="text-center font-bold text-xl pb-10">Previews will shown here.</div>
			</div>
		{/if}
	</div>
{/if}
