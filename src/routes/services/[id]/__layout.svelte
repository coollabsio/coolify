<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(service): string {
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
	export const load: Load = async ({ fetch, params, url }) => {
		let readOnly = false;
		const endpoint = `/services/${params.id}.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			const { service, isRunning, settings } = await res.json();
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
					service,
					isRunning
				},
				stuff: {
					service,
					isRunning,
					readOnly,
					settings
				}
			};
		}

		return {
			status: 302,
			redirect: '/services'
		};
	};
</script>

<script>
	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import Loading from '$lib/components/Loading.svelte';
	import { del, post } from '$lib/api';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	export let service;
	export let isRunning;

	let loading = false;

	async function deleteService() {
		const sure = confirm(`Are you sure you would like to delete '${service.name}'?`);
		if (sure) {
			loading = true;
			try {
				if (service.type) await post(`/services/${service.id}/${service.type}/stop.json`, {});
				await del(`/services/${service.id}/delete.json`, { id: service.id });
				return await goto(`/services`);
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		}
	}
	async function stopService() {
		const sure = confirm(`Are you sure you would like to stop '${service.name}'?`);
		if (sure) {
			loading = true;
			try {
				await post(`/services/${service.id}/${service.type}/stop.json`, {});
				return window.location.reload();
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		}
	}
	async function startService() {
		loading = true;
		try {
			await post(`/services/${service.id}/${service.type}/start.json`, {});
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<nav class="nav-side">
	{#if loading}
		<Loading fullscreen cover />
	{:else}
		{#if service.type && service.destinationDockerId && service.version && service.fqdn}
			{#if isRunning}
				<button
					on:click={stopService}
					title="Stop Service"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
					data-tooltip={$session.isAdmin
						? 'Stop Service'
						: 'You do not have permission to stop the service.'}
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
			{:else}
				<button
					on:click={startService}
					title="Start Service"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
					data-tooltip={$session.isAdmin
						? 'Start Service'
						: 'You do not have permission to start the service.'}
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
			{/if}
			<div class="border border-stone-700 h-8" />
		{/if}
		{#if service.type && service.destinationDockerId && service.version}
			<a
				href="/services/{id}"
				sveltekit:prefetch
				class="hover:text-yellow-500 rounded"
				class:text-yellow-500={$page.url.pathname === `/services/${id}`}
				class:bg-coolgray-500={$page.url.pathname === `/services/${id}`}
			>
				<button
					title="Configurations"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Configurations"
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
			<a
				href="/services/{id}/secrets"
				sveltekit:prefetch
				class="hover:text-pink-500 rounded"
				class:text-pink-500={$page.url.pathname === `/services/${id}/secrets`}
				class:bg-coolgray-500={$page.url.pathname === `/services/${id}/secrets`}
			>
				<button
					title="Secrets"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Secrets"
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
			<div class="border border-stone-700 h-8" />
		{/if}
		<button
			on:click={deleteService}
			title="Delete Service"
			type="submit"
			disabled={!$session.isAdmin}
			class:hover:text-red-500={$session.isAdmin}
			class="icons bg-transparent tooltip-bottom text-sm"
			data-tooltip={$session.isAdmin
				? 'Delete Service'
				: 'You do not have permission to delete a service.'}><DeleteIcon /></button
		>
	{/if}
</nav>
<slot />
