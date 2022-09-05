<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(service: any) {
		let configurationPhase = null;
		if (!service.type) {
			configurationPhase = 'type';
		} else if (!service.version) {
			configurationPhase = 'version';
		} else if (!service.destinationDockerId) {
			configurationPhase = 'destination';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ params, url }) => {
		try {
			let readOnly = false;
			const response = await get(`/services/${params.id}`);
			const { service, settings } = await response;
			if (!service || Object.entries(service).length === 0) {
				return {
					status: 302,
					redirect: '/databases'
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
					service
				},
				stuff: {
					service,
					readOnly,
					settings
				}
			};
		} catch (error) {
			console.log(error);
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { del, get, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { t } from '$lib/translations';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import {
		appSession,
		isDeploymentEnabled,
		status,
		location,
		setLocation,
		checkIfDeploymentEnabledServices
	} from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	const { id } = $page.params;

	export let service: any;

	$isDeploymentEnabled = checkIfDeploymentEnabledServices($appSession.isAdmin, service);

	let statusInterval: any;

	async function deleteService() {
		const sure = confirm($t('application.confirm_to_delete', { name: service.name }));
		if (sure) {
			$status.service.initialLoading = true;
			try {
				if (service.type && $status.service.isRunning)
					await post(`/services/${service.id}/${service.type}/stop`, {});
				await del(`/services/${service.id}`, { id: service.id });
				return await goto(`/services`);
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
			}
		}
	}
	async function stopService() {
		const sure = confirm($t('database.confirm_stop', { name: service.name }));
		if (sure) {
			$status.service.initialLoading = true;
			try {
				await post(`/services/${service.id}/${service.type}/stop`, {});
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
			}
		}
	}
	async function startService() {
		$status.service.initialLoading = true;
		$status.service.loading = true;
		try {
			await post(`/services/${service.id}/${service.type}/start`, {});
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
		$status.service.isRunning = data.isRunning;
		$status.service.isExited = data.isExited;
		$status.service.initialLoading = false;
		$status.service.loading = false;
	}
	onDestroy(() => {
		$status.service.initialLoading = true;
		$status.service.isRunning = false;
		$status.service.isExited = false;
		$status.service.loading = false;
		$location = null;
		$isDeploymentEnabled = false;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		setLocation(service);
		$status.service.isRunning = false;
		$status.service.loading = false;
		if (service.type && service.destinationDockerId && service.version && service.fqdn) {
			await getStatus();
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		} else {
			$status.service.initialLoading = false;
		}
	});
</script>

<nav class="nav-side">
	{#if $location}
		<a
			id="open"
			href={$location}
			target="_blank"
			class="icons  flex items-center bg-transparent text-sm"
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
		<div class="border border-stone-700 h-8" />
	{/if}
	{#if $status.service.isExited}
		<a
			id="error"
			href={$isDeploymentEnabled ? `/services/${id}/logs` : null}
			class="icons bg-transparent text-sm flex items-center text-red-500 tooltip-error"
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
		<Tooltip triggeredBy="#error">Service exited with an error!</Tooltip>
	{/if}
	{#if $status.service.initialLoading}
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
	{:else if $status.service.isRunning}
		<button
			id="stop"
			on:click={stopService}
			type="submit"
			disabled={!$isDeploymentEnabled}
			class="icons bg-transparent text-sm flex items-center space-x-2 text-red-500"
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
	{:else}
		<button
			id="start"
			on:click={startService}
			type="submit"
			disabled={!$isDeploymentEnabled}
			class="icons bg-transparent text-sm flex items-center space-x-2 text-green-500"
			><svg
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
		<Tooltip triggeredBy="#start">Start</Tooltip>
	{/if}
	<div class="border border-stone-700 h-8" />
	{#if service.type && service.destinationDockerId && service.version}
		<a
			href="/services/{id}"
			sveltekit:prefetch
			class="hover:text-yellow-500 rounded"
			class:text-yellow-500={$page.url.pathname === `/services/${id}`}
			class:bg-coolgray-500={$page.url.pathname === `/services/${id}`}
		>
			<button
				id="configuration"
				disabled={!$isDeploymentEnabled}
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
		<Tooltip triggeredBy="#configuration">Configuration</Tooltip>
		<a
			href="/services/{id}/secrets"
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/services/${id}/secrets`}
			class:bg-coolgray-500={$page.url.pathname === `/services/${id}/secrets`}
		>
			<button
				id="secrets"
				disabled={!$isDeploymentEnabled}
				class="icons bg-transparent text-sm "
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
						d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
					/>
					<circle cx="12" cy="11" r="1" />
					<line x1="12" y1="12" x2="12" y2="14.5" />
				</svg></button
			></a
		>
		<Tooltip triggeredBy="#secrets">Secrets</Tooltip>
		<a
			href="/services/{id}/storages"
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/services/${id}/storages`}
			class:bg-coolgray-500={$page.url.pathname === `/services/${id}/storages`}
		>
			<button
				id="persistentstorage"
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
		<Tooltip triggeredBy="#persistentstorage">Persistent Storage</Tooltip>
		<div class="border border-stone-700 h-8" />
		<a
			href={$isDeploymentEnabled && $status.service.isRunning ? `/services/${id}/logs` : null}
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/services/${id}/logs`}
			class:bg-coolgray-500={$page.url.pathname === `/services/${id}/logs`}
		>
			<button id="logs" disabled={!$status.service.isRunning} class="icons bg-transparent text-sm">
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
				</svg></button
			></a
		>
		<Tooltip triggeredBy="#logs">Logs</Tooltip>
	{/if}
	<button
		id="delete"
		on:click={deleteService}
		type="submit"
		disabled={!$appSession.isAdmin}
		class:hover:text-red-500={$appSession.isAdmin}
		class="icons bg-transparent  text-sm"><DeleteIcon /></button
	>
	<Tooltip triggeredBy="#delete">Delete</Tooltip>
</nav>
<slot />
