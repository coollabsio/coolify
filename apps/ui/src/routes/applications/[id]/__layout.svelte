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
					redirect: '/applications'
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
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
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
		checkIfDeploymentEnabledApplications
	} from '$lib/store';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import Tooltip from '$lib/components/Tooltip.svelte';

	let statusInterval: any;
	let forceDelete = false;
	const { id } = $page.params;

	$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);

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

	async function deleteApplication(name: string, force: boolean) {
		const sure = confirm($t('application.confirm_to_delete', { name }));
		if (sure) {
			$status.application.initialLoading = true;
			try {
				await del(`/applications/${id}`, { id, force });
				return await goto(`/applications`);
			} catch (error) {
				if (error.message.startsWith(`Command failed: SSH_AUTH_SOCK=/tmp/coolify-ssh-agent.pid`)) {
					forceDelete = true;
				}
				return errorNotification(error);
			} finally {
				$status.application.initialLoading = false;
			}
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
		$status.application.loading = false;
		$status.application.initialLoading = false;
	}

	onDestroy(() => {
		$status.application.initialLoading = true;
		$status.application.isRunning = false;
		$status.application.isExited = false;
		$status.application.loading = false;
		$location = null;
		$isDeploymentEnabled = false;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		setLocation(application, settings);
		$status.application.isRunning = false;
		$status.application.isExited = false;
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

<nav class="nav-side">
	{#if $location}
		<a
			id="open"
			href={$location}
			target="_blank"
			class="icons flex items-center bg-transparent text-sm"
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
		<Tooltip triggeredBy="#open">Open</Tooltip>

		<div class="border border-coolgray-500 h-8" />
	{/if}

	{#if $status.application.isExited}
		<a
			id="applicationerror"
			href={$isDeploymentEnabled ? `/applications/${id}/logs` : null}
			class="icons bg-transparent text-sm flex items-center text-error"
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
		<button
			class="icons flex animate-spin items-center space-x-2 bg-transparent text-sm duration-500 ease-in-out"
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
	{:else if $status.application.isRunning}
		<button
			id="stop"
			on:click={stopApplication}
			type="submit"
			disabled={!$isDeploymentEnabled}
			class="icons bg-transparent text-sm flex items-center space-x-2 text-error"
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
		<Tooltip triggeredBy="#restart">Restart (useful to change secrets)</Tooltip>

		<form on:submit|preventDefault={() => handleDeploySubmit(true)}>
			<button
				id="forceredeploy"
				type="submit"
				disabled={!$isDeploymentEnabled}
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
					<path
						d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
						transform="rotate(-45 12 12)"
					/>
				</svg>
			</button>
			<Tooltip triggeredBy="#forceredeploy">Force redeploy (without cache)</Tooltip>
		</form>
	{:else}
		<form on:submit|preventDefault={() => handleDeploySubmit(false)}>
			<button
				id="deploy"
				type="submit"
				disabled={!$isDeploymentEnabled}
				class="icons bg-transparent text-sm flex items-center space-x-2 text-success"
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
					<path d="M7 4v16l13 -8z" />
				</svg>
			</button>
			<Tooltip triggeredBy="#deploy">Deploy</Tooltip>
		</form>
	{/if}

	<div class="border border-coolgray-500 h-8" />
	<a
		href={$isDeploymentEnabled ? `/applications/${id}` : null}
		sveltekit:prefetch
		class="hover:text-yellow-500 rounded"
		class:text-yellow-500={$page.url.pathname === `/applications/${id}`}
		class:bg-coolgray-500={$page.url.pathname === `/applications/${id}`}
	>
		<button
			disabled={!$isDeploymentEnabled}
			id="configurations"
			class="icons bg-transparent text-sm"
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
			</svg></button
		></a
	>

	<Tooltip triggeredBy="#configurations">Configurations</Tooltip>
	<a
		href={$isDeploymentEnabled ? `/applications/${id}/secrets` : null}
		sveltekit:prefetch
		class="hover:text-pink-500 rounded"
		class:text-pink-500={$page.url.pathname === `/applications/${id}/secrets`}
		class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/secrets`}
	>
		<button id="secrets" disabled={!$isDeploymentEnabled} class="icons bg-transparent text-sm">
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
					d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
				/>
				<circle cx="12" cy="11" r="1" />
				<line x1="12" y1="12" x2="12" y2="14.5" />
			</svg></button
		></a
	>
	<Tooltip triggeredBy="#secrets">Secrets</Tooltip>
	<a
		href={$isDeploymentEnabled ? `/applications/${id}/storages` : null}
		sveltekit:prefetch
		class="hover:text-pink-500 rounded"
		class:text-pink-500={$page.url.pathname === `/applications/${id}/storages`}
		class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/storages`}
	>
		<button
			id="persistentstorages"
			disabled={!$isDeploymentEnabled}
			class="icons bg-transparent text-sm"
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
				<ellipse cx="12" cy="6" rx="8" ry="3" />
				<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
				<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
			</svg>
		</button></a
	>
	<Tooltip triggeredBy="#persistentstorages">Persistent Storages</Tooltip>
	{#if !application.settings.isBot}
		<a
			href={$isDeploymentEnabled ? `/applications/${id}/previews` : null}
			sveltekit:prefetch
			class="hover:text-orange-500 rounded"
			class:text-orange-500={$page.url.pathname === `/applications/${id}/previews`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/previews`}
		>
			<button id="previews" disabled={!$isDeploymentEnabled} class="icons bg-transparent text-sm">
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
					<circle cx="7" cy="18" r="2" />
					<circle cx="7" cy="6" r="2" />
					<circle cx="17" cy="12" r="2" />
					<line x1="7" y1="8" x2="7" y2="16" />
					<path d="M7 8a4 4 0 0 0 4 4h4" />
				</svg></button
			></a
		>
		<Tooltip triggeredBy="#previews">Previews</Tooltip>
	{/if}
	<div class="border border-coolgray-500 h-8" />
	<a
		href={$isDeploymentEnabled && $status.application.isRunning ? `/applications/${id}/logs` : null}
		sveltekit:prefetch
		class="hover:text-sky-500 rounded"
		class:text-sky-500={$page.url.pathname === `/applications/${id}/logs`}
		class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs`}
	>
		<button
			id="applicationlogs"
			disabled={!$isDeploymentEnabled || !$status.application.isRunning}
			class="icons bg-transparent text-sm"
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
				<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<line x1="3" y1="6" x2="3" y2="19" />
				<line x1="12" y1="6" x2="12" y2="19" />
				<line x1="21" y1="6" x2="21" y2="19" />
			</svg>
		</button></a
	>
	<Tooltip triggeredBy="#applicationlogs">Application Logs</Tooltip>
	<a
		href={$isDeploymentEnabled ? `/applications/${id}/logs/build` : null}
		sveltekit:prefetch
		class="hover:text-red-500 rounded"
		class:text-red-500={$page.url.pathname === `/applications/${id}/logs/build`}
		class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs/build`}
	>
		<button id="buildlogs" disabled={!$isDeploymentEnabled} class="icons bg-transparent text-sm">
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
				<circle cx="19" cy="13" r="2" />
				<circle cx="4" cy="17" r="2" />
				<circle cx="13" cy="17" r="2" />
				<line x1="13" y1="19" x2="4" y2="19" />
				<line x1="4" y1="15" x2="13" y2="15" />
				<path d="M8 12v-5h2a3 3 0 0 1 3 3v5" />
				<path d="M5 15v-2a1 1 0 0 1 1 -1h7" />
				<path d="M19 11v-7l-6 7" />
			</svg>
		</button></a
	>
	<Tooltip triggeredBy="#buildlogs">Build Logs</Tooltip>
	<div class="border border-coolgray-500 h-8" />

	{#if forceDelete}
		<button
			id="forcedelete"
			on:click={() => deleteApplication(application.name, true)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:bg-red-600={$appSession.isAdmin}
			class:hover:bg-red-500={$appSession.isAdmin}
			class="icons bg-transparent text-sm"
		>
			Force Delete
		</button>
		<Tooltip triggeredBy="#forcedelete">Force Delete</Tooltip>
	{:else}
		<button
			id="delete"
			on:click={() => deleteApplication(application.name, false)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:hover:text-red-500={$appSession.isAdmin}
			class="icons bg-transparent text-sm"
		>
			<DeleteIcon />
		</button>
		<Tooltip triggeredBy="#delete">Delete</Tooltip>
	{/if}
</nav>
<slot />
