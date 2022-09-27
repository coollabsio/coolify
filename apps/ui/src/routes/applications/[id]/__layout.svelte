<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(application: any): string | null {
		let configurationPhase = null;
		if (!application.gitSourceId) {
			configurationPhase = 'source';
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
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
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
	import Tooltip from '$lib/components/Tooltip.svelte';
	import Menu from './_Menu.svelte';

	let statusInterval: any;
	let forceDelete = false;

	const { id } = $page.params;
	$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);

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
		try {
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
			$status.application.initialLoading = true;
			// $status.application.loading = true;
			await post(`/applications/${id}/stop`, {});
		} catch (error) {
			return errorNotification(error);
		} finally {
			$status.application.initialLoading = false;
			// $status.application.loading = false;
			await getStatus();
		}
	}
	async function getStatus() {
		if ($status.application.loading) return;
		$status.application.loading = true;
		const data = await get(`/applications/${id}/status`);
		$status.application.isRunning = data.isRunning;
		$status.application.isExited = data.isExited;
		$status.application.isRestarting = data.isRestarting;
		$status.application.loading = false;
		$status.application.initialLoading = false;
	}

	onDestroy(() => {
		$status.application.initialLoading = true;
		$status.application.isRunning = false;
		$status.application.isExited = false;
		$status.application.isRestarting = false;
		$status.application.loading = false;
		$location = null;
		$isDeploymentEnabled = false;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		setLocation(application, settings);
		$status.application.isRunning = false;
		$status.application.isExited = false;
		$status.application.isRestarting = false;
		$status.application.loading = false;
		if (
			application.gitSourceId &&
			application.destinationDockerId &&
			(application.fqdn || application.settings.isBot)
		) {
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
						class="badge rounded uppercase"
						class:text-green-500={$status.application.isRunning}
						class:text-red-500={!$status.application.isRunning}
					>
						{$status.application.isRunning ? 'Running' : 'Stopped'}
					</div>
				</div>
			{/if}
		</div>
		{#if $page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
		<div class="px-2">
			{#if forceDelete}
				<button
					on:click={() => deleteApplication(application.name, true)}
					disabled={!$appSession.isAdmin}
					class:bg-red-600={$appSession.isAdmin}
					class:hover:bg-red-500={$appSession.isAdmin}
					class="btn btn-sm btn-error text-sm"
				>
					Force Delete Application
				</button>
			{:else}
				<button
					on:click={() => deleteApplication(application.name, false)}
					disabled={!$appSession.isAdmin}
					class:bg-red-600={$appSession.isAdmin}
					class:hover:bg-red-500={$appSession.isAdmin}
					class="btn btn-sm btn-error text-sm"
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
		{#if $status.application.isExited || $status.application.isRestarting}
			<a
				id="applicationerror"
				href={$isDeploymentEnabled ? `/applications/${id}/logs` : null}
				class="icons bg-transparent text-sm text-error"
				sveltekit:prefetch
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
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
			</a>
			<Tooltip triggeredBy="#applicationerror">Application exited with an error!</Tooltip>
		{/if}
		{#if $status.application.initialLoading}
			<button class="icons animate-spin bg-transparent duration-500 ease-in-out">
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
		{:else if $status.application.isRunning}
			<button
				id="stop"
				on:click={stopApplication}
				type="submit"
				disabled={!$isDeploymentEnabled}
				class="icons bg-transparent text-error"
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
					<rect x="6" y="5" width="4" height="14" rx="1" />
					<rect x="14" y="5" width="4" height="14" rx="1" />
				</svg>
			</button>
			<Tooltip triggeredBy="#stop">Stop</Tooltip>

			<button
				id="restart"
				on:click={restartApplication}
				type="submit"
				disabled={!$isDeploymentEnabled}
				class="icons bg-transparent"
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
			<Tooltip triggeredBy="#restart">Restart (useful to change secrets)</Tooltip>

			<button
				id="forceredeploy"
				disabled={!$isDeploymentEnabled}
				class="icons bg-transparent "
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
			</button>
			<Tooltip triggeredBy="#forceredeploy">Force Redeploy (without cache)</Tooltip>
		{:else if $isDeploymentEnabled}
			<button
				class="icons flex items-center font-bold"
				disabled={!$isDeploymentEnabled}
				on:click={() => handleDeploySubmit(false)}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6 mr-2 text-green-500"
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
				Deploy
			</button>
		{/if}

		{#if $location && $status.application.isRunning}
			<a id="openApplication" href={$location} target="_blank" class="icons bg-transparent "
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
			<Tooltip triggeredBy="#openApplication">Open Application</Tooltip>
		{/if}
	</div>
</div>
<div
	class="mx-auto max-w-screen-2xl px-0 lg:px-2 grid grid-cols-1"
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
