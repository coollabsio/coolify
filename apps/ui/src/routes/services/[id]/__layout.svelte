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
	import ThingStatusToggler from '$lib/components/buttons/ThingStatusToggler.svelte';
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
	<div class='title'>
		{#if $page.url.pathname === `/services/${id}/configuration/type`}
			Select a Service Type
		{:else if $page.url.pathname === `/services/${id}/configuration/version`}
			Select a Service Version
		{:else if $page.url.pathname === `/services/${id}/configuration/destination`}
			Select a Destination
		{:else}
			Configurations
		{/if}
	</div>

	<div slot="actions">
		<ThingStatusToggler {id} 
				what='services' 
				thing={service} 
				valid={true}
			/>
		<StatusBadge thing={service} />
		{#if $status.service.overallStatus === 'stopped'}
			<DeployButton disabled={!$isDeploymentEnabled} action={startService}/>	
		{/if}
		{#if $page.url.pathname.startsWith(`/services/${id}/configuration/`)}
			<DeleteButton action={deleteService} disabled={!$appSession.isAdmin} />
		{/if}
	</div>
	
</ContextMenu>
<!-- ServiceStatusToggler -->

	<!-- class:lg:grid-cols-4={!$page.url.pathname.startsWith(`/services/${id}/configuration/`)} -->

{#if !$page.url.pathname.startsWith(`/services/${id}/configuration/`) }
	<LeftSidebar>
		<div slot="sidebar">
			<nav class="header flex flex-col lg:p-0 ">
				<Menu {service} {template} />
			</nav>
		</div>
		<div slot="content">
			<slot />
		</div>
	</LeftSidebar>
{:else}
	<slot/>
{/if}
