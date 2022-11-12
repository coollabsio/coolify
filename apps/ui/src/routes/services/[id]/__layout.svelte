<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(service: any) {
		let configurationPhase = null;
		if (!service.type) {
			configurationPhase = 'type';
		} else if (!service.destinationDockerId) {
			configurationPhase = 'destination';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ params, url }) => {
		try {
			let readOnly = false;
			const response = await get(`/services/${params.id}`);
			const { service, settings, template, tags } = await response;
			if (!service || Object.entries(service).length === 0) {
				return {
					status: 302,
					redirect: '/'
				};
			}
			const configurationPhase = checkConfiguration(service);
			if (
				configurationPhase &&
				url.pathname !== `/services/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/services/${params.id}/configuration/${configurationPhase}`
				};
			}
			if (service.plausibleAnalytics?.email && service.plausibleAnalytics.username) readOnly = true;
			if (service.wordpress?.mysqlDatabase) readOnly = true;
			if (service.ghost?.mariadbDatabase && service.ghost.mariadbDatabase) readOnly = true;

			return {
				props: {
					service,
					template
				},
				stuff: {
					service,
					template,
					readOnly,
					settings,
					tags
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let service: any;
	export let template: any;
	import { page } from '$app/stores';
	import { del, get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import {
		appSession,
		isDeploymentEnabled,
		status,
		location,
		setLocation,
		checkIfDeploymentEnabledServices,
		addToast
	} from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import Menu from './_Menu.svelte';
	import { saveForm } from './utils';
	import { dev } from '$app/env';
	import LeftSidebar from '$lib/components/LeftSidebar.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import DeployButton from '$lib/components/buttons/DeployButton.svelte';
	import DeleteButton from '$lib/components/buttons/DeleteButton.svelte';
	import StatusBadge from '$lib/components/badges/StatusBadge.svelte';
	const { id } = $page.params;

	$isDeploymentEnabled = checkIfDeploymentEnabledServices($appSession.isAdmin, service);

	let statusInterval: any;
	

	async function deleteService() {
		const sure = confirm($t('application.confirm_to_delete', { name: service.name }));
		if (sure) {
			$status.service.initialLoading = true;
			try {
				if (service.type && $status.service.isRunning)
					await post(`/services/${service.id}/stop`, {});
				await del(`/services/${service.id}`, { id: service.id });
				return await goto('/');
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
			}
		}
	}
	async function restartService() {
		const sure = confirm('Are you sure you want to restart this service?');
		if (sure) {
			await stopService(true);
			await startService();
		}
	}
	async function stopService(skip = false) {
		if (skip) {
			$status.service.initialLoading = true;
			$status.service.loading = true;
			try {
				await post(`/services/${service.id}/stop`, {});
				if (service.type.startsWith('wordpress')) {
					await post(`/services/${id}/wordpress/ftp`, {
						ftpEnabled: false
					});
					service.wordpress?.ftpEnabled && window.location.reload();
				}
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
				$status.service.loading = false;
				await getStatus();
			}
			return;
		}
		const sure = confirm($t('database.confirm_stop', { name: service.name }));
		if (sure) {
			$status.service.initialLoading = true;
			$status.service.loading = true;
			try {
				await post(`/services/${service.id}/stop`, {});
				if (service.type.startsWith('wordpress')) {
					await post(`/services/${id}/wordpress/ftp`, {
						ftpEnabled: false
					});
					service.wordpress?.ftpEnabled && window.location.reload();
				}
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
				$status.service.loading = false;
				await getStatus();
			}
		}
	}
	async function startService() {
		$status.service.initialLoading = true;
		$status.service.loading = true;
		try {
			const form: any = document.getElementById('saveForm');
			if (form) {
				const formData = new FormData(form);
				service = await saveForm(formData, service);
			}
			await post(`/services/${service.id}/start`, {});
		} catch (error) {
			return errorNotification(error);
		} finally {
			$status.service.initialLoading = false;
			$status.service.loading = false;
			await getStatus();
		}
	}
	async function getStatus() {
		if ($status.service.loading) return;
		$status.service.loading = true;
		const data = await get(`/services/${id}/status`);

		$status.service.statuses = data;
		let numberOfServices = Object.keys(data).length;

		if (Object.keys($status.service.statuses).length === 0) {
			$status.service.overallStatus = 'stopped';
		} else {
			if (Object.keys($status.service.statuses).length !== numberOfServices) {
				$status.service.overallStatus = 'degraded';
			} else {
				for (const oneService in $status.service.statuses) {
					const { isExited, isRestarting, isRunning } = $status.service.statuses[oneService].status;
					if (isExited || isRestarting) {
						$status.service.overallStatus = 'degraded';
						break;
					}
					if (isRunning) {
						$status.service.overallStatus = 'healthy';
					}
					if (!isExited && !isRestarting && !isRunning) {
						$status.service.overallStatus = 'stopped';
					}
				}
			}
		}
		$status.service.loading = false;
		$status.service.initialLoading = false;
	}
	onDestroy(() => {
		$status.service.initialLoading = true;
		$status.service.loading = false;
		$status.service.statuses = [];
		$status.service.overallStatus = 'stopped';
		$location = null;
		$isDeploymentEnabled = false;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		setLocation(service);
		$status.service.loading = false;
		if ($isDeploymentEnabled) {
			await getStatus();
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		} else {
			$status.service.initialLoading = false;
		}
	});
</script>

<ContextMenu>
	<div class="title flex flex-1 justify-between items-center">
		{#if $page.url.pathname === `/services/${id}/configuration/type`}
			Select a Service Type
		{:else if $page.url.pathname === `/services/${id}/configuration/version`}
			Select a Service Version
		{:else if $page.url.pathname === `/services/${id}/configuration/destination`}
			Select a Destination
		{:else}
			<div>Configurations</div>
			<div>
				<StatusBadge status={$status.service.overallStatus} />
				{#if $status.service.overallStatus === 'stopped'}
					<DeployButton disabled={!$isDeploymentEnabled} action={startService}/>	
				{/if}
				{#if $page.url.pathname.startsWith(`/services/${id}/configuration/`)}
					<DeleteButton action={deleteService} disabled={!$appSession.isAdmin} />
				{/if}
			</div>
		{/if}
	</div>
</ContextMenu>


{#if $status.service.initialLoading}
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
		{$status.service.startup[id] || 'Loading...'}
	</button>
{:else if $status.service.overallStatus === 'healthy'}
	<button
		disabled={!$isDeploymentEnabled}
		class="btn btn-sm gap-2"
		on:click={() => restartService()}
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
		on:click={() => stopService(false)}
		type="submit"
		disabled={!$isDeploymentEnabled}
		class="btn btn-sm gap-2"
	>
		<svg
			xmlns="http://www.w3.org/2000/svg"
			class="w-6 h-6 text-error "
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
		Stop
	</button>
{:else if $status.service.overallStatus === 'degraded'}
	<button
		on:click={stopService}
		type="submit"
		disabled={!$isDeploymentEnabled}
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
{:else if $status.service.overallStatus === 'stopped'}
	{#if $status.service.overallStatus === 'degraded'}
		<button
			class="btn btn-sm gap-2"
			disabled={!$isDeploymentEnabled}
			on:click={() => restartService()}
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
			{$status.application.statuses.length === 1 ? 'Force Redeploy' : 'Redeploy Stack'}
		</button>
	{/if}
{/if}

	<!-- class:lg:grid-cols-4={!$page.url.pathname.startsWith(`/services/${id}/configuration/`)} -->

<br/>
<LeftSidebar>
	<div slot="sidebar">
		{#if !$page.url.pathname.startsWith(`/services/${id}/configuration/`)}
			<nav class="header flex flex-col lg:pt-0 ">
				<Menu {service} {template} />
			</nav>
		{/if}
	</div>
	<div slot="content">
		<slot />
	</div>
</LeftSidebar>
