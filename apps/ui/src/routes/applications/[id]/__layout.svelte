<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(application: any): string | null {
		let configurationPhase = null;
		if (!application.gitSourceId && !application.simpleDockerfile) {
			return (configurationPhase = 'source');
		}
		if (application.simpleDockerfile) {
			if (!application.destinationDockerId) {
				configurationPhase = 'destination';
			}
			return configurationPhase;
		} else if (!application.repository && !application.branch) {
			configurationPhase = 'repository';
		} else if (!application.destinationDockerId) {
			configurationPhase = 'destination';
		} else if (!application.buildPack) {
			configurationPhase = 'buildpack';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, url, params }) => {
		try {
			const response = await get(`/applications/${params.id}`);
			let { application, appId, settings } = response;
			if (!application || Object.entries(application).length === 0) {
				return {
					status: 302,
					redirect: '/'
				};
			}
			const configurationPhase = checkConfiguration(application);
			if (
				configurationPhase &&
				url.pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/applications/${params.id}/configuration/${configurationPhase}`
				};
			}

			return {
				props: {
					application,
					settings
				},
				stuff: {
					application,
					appId,
					settings
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let application: any;
	export let settings: any;
	import { page } from '$app/stores';
	import { del, get, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { onDestroy, onMount } from 'svelte';
	import { t } from '$lib/translations';
	import {
		appSession,
		status,
		location,
		setLocation,
		addToast,
		isDeploymentEnabled,
		checkIfDeploymentEnabledApplications,
		selectedBuildId
	} from '$lib/store';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import Menu from './_Menu.svelte';
	import { saveForm } from './utils';

	let statusInterval: any;
	let forceDelete = false;
	let stopping = false;
	const { id } = $page.params;
	$isDeploymentEnabled = checkIfDeploymentEnabledApplications(application);

	async function deleteApplication(name: string, force: boolean) {
		const sure = confirm($t('application.confirm_to_delete', { name }));
		if (sure) {
			try {
				await del(`/applications/${id}`, { id, force });
				return await goto('/');
			} catch (error) {
				if (error.message.startsWith(`Command failed: SSH_AUTH_SOCK=/tmp/coolify-ssh-agent.pid`)) {
					forceDelete = true;
				}
				return errorNotification(error);
			}
		}
	}

	async function handleDeploySubmit(forceRebuild = false) {
		if (!$isDeploymentEnabled) return;
		if (application.gitCommitHash && !application.settings.isPublicRepository) {
			const sure = await confirm(
				`Are you sure you want to deploy a specific commit (${application.gitCommitHash})? This will disable the "Automatic Deployment" feature to prevent accidental overwrites of incoming commits.`
			);
			if (!sure) {
				return;
			} else {
				await post(`/applications/${id}/settings`, {
					autodeploy: false
				});
			}
		}
		if (!statusInterval) {
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		}
		try {
			await saveForm(id, application);
			const { buildId } = await post(`/applications/${id}/deploy`, {
				...application,
				forceRebuild
			});
			addToast({
				message: $t('application.deployment_queued'),
				type: 'success'
			});
			$selectedBuildId = buildId;
			return await goto(`/applications/${id}/logs/build?buildId=${buildId}`, {
				replaceState: true
			});
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function restartApplication() {
		try {
			$status.application.initialLoading = true;
			$status.application.loading = true;
			await post(`/applications/${id}/restart`, {});
			addToast({
				type: 'success',
				message: 'Restart successful.'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			$status.application.initialLoading = false;
			$status.application.loading = false;
			await getStatus();
		}
	}
	async function stopApplication() {
		try {
			stopping = true;
			await post(`/applications/${id}/stop`, {});
		} catch (error) {
			return errorNotification(error);
		} finally {
			stopping = false;
			await getStatus();
		}
	}
	async function getStatus() {
		if (($status.application.loading && stopping) || $status.application.restarting === true)
			return;
		$status.application.loading = true;
		const data = await get(`/applications/${id}/status`);

		$status.application.statuses = data;
		let numberOfApplications = 0;
		if (application.dockerComposeConfiguration) {
			numberOfApplications =
				application.buildPack === 'compose'
					? Object.entries(JSON.parse(application.dockerComposeConfiguration)).length
					: 1;
		} else {
			numberOfApplications = 1;
		}

		if ($status.application.statuses.length === 0) {
			$status.application.overallStatus = 'stopped';
		} else {
			for (const oneStatus of $status.application.statuses) {
				if (oneStatus.status.isExited || oneStatus.status.isRestarting) {
					$status.application.overallStatus = 'degraded';
					break;
				}
				if (oneStatus.status.isRunning) {
					$status.application.overallStatus = 'healthy';
				}
				if (
					!oneStatus.status.isExited &&
					!oneStatus.status.isRestarting &&
					!oneStatus.status.isRunning
				) {
					$status.application.overallStatus = 'stopped';
				}
			}
		}
		$status.application.loading = false;
		$status.application.initialLoading = false;
	}

	onDestroy(() => {
		$status.application.initialLoading = true;
		$status.application.loading = false;
		$status.application.statuses = [];
		$status.application.overallStatus = 'stopped';
		$location = null;
		$isDeploymentEnabled = false;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		setLocation(application, settings);
		$status.application.loading = false;
		if ($isDeploymentEnabled) {
			await getStatus();
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		} else {
			$status.application.initialLoading = false;
		}
	});
</script>

<div class="mx-auto max-w-screen-2xl px-6 grid grid-cols-1 lg:grid-cols-2">
	<nav class="header flex flex-row order-2 lg:order-1 px-0 lg:px-4 items-start">
		<div class="title lg:pb-10">
			{#if $page.url.pathname === `/applications/${id}/configuration/source`}
				Select a Source
			{:else if $page.url.pathname === `/applications/${id}/configuration/destination`}
				Select a Destination
			{:else if $page.url.pathname === `/applications/${id}/configuration/repository`}
				Select a Repository
			{:else if $page.url.pathname === `/applications/${id}/configuration/buildpack`}
				Select a Build Pack
			{:else}
				<div class="flex justify-center items-center space-x-2">
					<div>Configurations</div>
					<div
						class="badge badge-lg rounded uppercase"
						class:text-green-500={$status.application.overallStatus === 'healthy'}
						class:text-yellow-400={$status.application.overallStatus === 'degraded'}
						class:text-red-500={$status.application.overallStatus === 'stopped'}
					>
						{$status.application.overallStatus === 'healthy'
							? 'Healthy'
							: $status.application.overallStatus === 'degraded'
							? 'Degraded'
							: 'Stopped'}
					</div>
				</div>
			{/if}
		</div>
		{#if $page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
			<div class="px-4">
				{#if forceDelete}
					<button
						on:click={() => deleteApplication(application.name, true)}
						disabled={!$appSession.isAdmin}
						class:bg-red-600={$appSession.isAdmin}
						class:hover:bg-red-500={$appSession.isAdmin}
						class="btn btn-sm btn-error hover:bg-red-700 text-sm w-64"
					>
						Force Delete Application
					</button>
				{:else}
					<button
						on:click={() => deleteApplication(application.name, false)}
						disabled={!$appSession.isAdmin}
						class:bg-red-600={$appSession.isAdmin}
						class:hover:bg-red-500={$appSession.isAdmin}
						class="btn btn-sm btn-error hover:bg-red-700 text-sm w-64"
					>
						Delete Application
					</button>
				{/if}
			</div>
		{/if}
	</nav>
	<div
		class="pt-4 flex flex-row items-start justify-center lg:justify-end space-x-2 order-1 lg:order-2"
	>
		{#if $status.application.overallStatus === 'degraded' && application.buildPack !== 'compose'}
			<a
				href={$isDeploymentEnabled ? `/applications/${id}/logs` : null}
				class="btn btn-sm text-sm gap-2"
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6 text-red-500"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentcolor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path
						d="M8.7 3h6.6c.3 0 .5 .1 .7 .3l4.7 4.7c.2 .2 .3 .4 .3 .7v6.6c0 .3 -.1 .5 -.3 .7l-4.7 4.7c-.2 .2 -.4 .3 -.7 .3h-6.6c-.3 0 -.5 -.1 -.7 -.3l-4.7 -4.7c-.2 -.2 -.3 -.4 -.3 -.7v-6.6c0 -.3 .1 -.5 .3 -.7l4.7 -4.7c.2 -.2 .4 -.3 .7 -.3z"
					/>
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
				Application Error
			</a>
		{/if}
		{#if stopping}
			<button class="btn btn-ghost btn-sm gap-2">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 animate-spin duration-500 ease-in-out"
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
				Stopping...
			</button>
		{:else if $status.application.initialLoading}
			<button class="btn btn-ghost btn-sm gap-2">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 animate-spin duration-500 ease-in-out"
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
				Loading...
			</button>
		{:else if $status.application.overallStatus === 'healthy'}
			{#if application.buildPack !== 'compose'}
				<button
					on:click={restartApplication}
					type="submit"
					disabled={!$isDeploymentEnabled || !$appSession.isAdmin}
					class="btn btn-sm gap-2"
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
					</svg> Restart
				</button>
			{/if}
			<button
				disabled={!$isDeploymentEnabled || !$appSession.isAdmin}
				class="btn btn-sm gap-2"
				on:click={() => handleDeploySubmit(true)}
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
				</svg>

				Force Redeploy
			</button>
			<button
				on:click={stopApplication}
				type="submit"
				disabled={!$isDeploymentEnabled || !$appSession.isAdmin}
				class="btn btn-sm  gap-2"
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6 text-error"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<rect x="6" y="5" width="4" height="14" rx="1" />
					<rect x="14" y="5" width="4" height="14" rx="1" />
				</svg> Stop
			</button>
		{:else if $isDeploymentEnabled && !$page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
			{#if $status.application.overallStatus === 'degraded'}
				<button
					on:click={stopApplication}
					type="submit"
					disabled={!$isDeploymentEnabled || !$appSession.isAdmin}
					class="btn btn-sm gap-2"
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-6 h-6 text-error"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<rect x="6" y="5" width="4" height="14" rx="1" />
						<rect x="14" y="5" width="4" height="14" rx="1" />
					</svg> Stop
				</button>
			{/if}
			<button
				class="btn btn-sm gap-2"
				disabled={!$isDeploymentEnabled || !$appSession.isAdmin}
				on:click={() => handleDeploySubmit(true)}
			>
				{#if $status.application.overallStatus !== 'degraded'}
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-6 h-6 text-pink-500"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path d="M7 4v16l13 -8z" />
					</svg>
				{:else}
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
					</svg>
				{/if}
				{$status.application.overallStatus === 'degraded'
					? $status.application.statuses.length === 1
						? 'Force Redeploy'
						: 'Redeploy Stack'
					: 'Deploy'}
			</button>
		{/if}
		{#if $location && $status.application.overallStatus === 'healthy'}
			<a href={$location} target="_blank noreferrer" class="btn btn-sm gap-2 text-sm bg-primary"
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
				</svg>Open</a
			>
		{/if}
	</div>
</div>
<div
	class="mx-auto max-w-screen-2xl px-0 lg:px-10 grid grid-cols-1"
	class:lg:grid-cols-4={!$page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
>
	{#if !$page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
		<nav class="header flex flex-col lg:pt-0 ">
			<Menu {application} />
		</nav>
	{/if}
	<div class="pt-0 col-span-0 lg:col-span-3 pb-24">
		<slot />
	</div>
</div>
