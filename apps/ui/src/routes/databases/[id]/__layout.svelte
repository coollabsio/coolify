<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(database: any): any {
		let configurationPhase = null;
		if (!database.type) {
			configurationPhase = 'type';
		} else if (!database.version) {
			configurationPhase = 'version';
		} else if (!database.destinationDockerId) {
			configurationPhase = 'destination';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, url, params }) => {
		try {
			const { id } = params;
			const response = await get(`/databases/${id}`);
			const { database, versions, privatePort, settings } = response;
			if (id !== 'new' && (!database || Object.entries(database).length === 0)) {
				return {
					status: 302,
					redirect: '/databases'
				};
			}
			const configurationPhase = checkConfiguration(database);
			if (
				configurationPhase &&
				url.pathname !== `/databases/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/databases/${params.id}/configuration/${configurationPhase}`
				};
			}
			return {
				props: {
					database,
					versions,
					privatePort
				},
				stuff: {
					database,
					versions,
					privatePort,
					settings
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let database: any;
	import { del, get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession, status, disabledButton } from '$lib/store';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import Loading from '$lib/components/Loading.svelte';
	import { onDestroy, onMount } from 'svelte';
	const { id } = $page.params;

	let loading = false;
	let statusInterval: any = false;

	$disabledButton = !$appSession.isAdmin;

	async function deleteDatabase() {
		const sure = confirm(`Are you sure you would like to delete '${database.name}'?`);
		if (sure) {
			loading = true;
			try {
				await del(`/databases/${database.id}`, { id: database.id });
				return await goto('/databases');
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		}
	}
	async function stopDatabase() {
		const sure = confirm($t('database.confirm_stop', { name: database.name }));
		if (sure) {
			loading = true;
			try {
				await post(`/databases/${database.id}/stop`, {});
				return window.location.reload();
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	async function startDatabase() {
		loading = true;
		try {
			await post(`/databases/${database.id}/start`, {});
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function getStatus() {
		if ($status.database.loading) return;
		$status.database.loading = true;
		const data = await get(`/databases/${id}/status`);
		$status.database.isRunning = data.isRunning;
		$status.database.initialLoading = false;
		$status.database.loading = false;
	}
	onDestroy(() => {
		$status.database.initialLoading = true;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		$status.database.isRunning = false;
		$status.database.loading = false;
		if (
			database.type &&
			database.destinationDockerId &&
			database.version &&
			database.defaultDatabase
		) {
			await getStatus();
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		} else {
			$status.database.initialLoading = false;
		}
	});
</script>

{#if id !== 'new'}
	<nav class="nav-side">
		{#if loading}
			<Loading fullscreen cover />
		{:else}
			{#if database.type && database.destinationDockerId && database.version && database.defaultDatabase}
				{#if $status.database.isExited}
					<a
						href={!$disabledButton ? `/databases/${id}/logs` : null}
						class="icons bg-transparent tooltip tooltip-bottom text-sm flex items-center text-red-500 tooltip-error"
						data-tip="Service exited with an error!"
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
				{/if}
				{#if $status.database.initialLoading}
					<button
						class="icons tooltip-bottom flex animate-spin items-center space-x-2 bg-transparent text-sm duration-500 ease-in-out"
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
				{:else if $status.database.isRunning}
					<button
						on:click={stopDatabase}
						type="submit"
						disabled={!$appSession.isAdmin}
						class="icons bg-transparent tooltip tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
						data-tip={$appSession.isAdmin
							? $t('database.stop_database')
							: $t('database.permission_denied_stop_database')}
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
						on:click={startDatabase}
						type="submit"
						disabled={!$appSession.isAdmin}
						class="icons bg-transparent tooltip tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
						data-tip={$appSession.isAdmin
							? $t('database.start_database')
							: $t('database.permission_denied_start_database')}
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
			{/if}
			<div class="border border-stone-700 h-8" />
			<a
				href="/databases/{id}"
				sveltekit:prefetch
				class="hover:text-yellow-500 rounded"
				class:text-yellow-500={$page.url.pathname === `/databases/${id}`}
				class:bg-coolgray-500={$page.url.pathname === `/databases/${id}`}
			>
				<button
					class="icons bg-transparent tooltip tooltip-bottom text-sm disabled:text-red-500"
					data-tip={$t('application.configurations')}
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
			<div class="border border-stone-700 h-8" />
			<a
				href={$status.database.isRunning ? `/databases/${id}/logs` : null}
				sveltekit:prefetch
				class="hover:text-pink-500 rounded"
				class:text-pink-500={$page.url.pathname === `/databases/${id}/logs`}
				class:bg-coolgray-500={$page.url.pathname === `/databases/${id}/logs`}
			>
				<button
					disabled={!$status.database.isRunning}
					class="icons bg-transparent tooltip tooltip-bottom text-sm"
					data-tip={$t('database.logs')}
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
					</svg></button
				></a
			>
			<button
				on:click={deleteDatabase}
				type="submit"
				disabled={!$appSession.isAdmin}
				class:hover:text-red-500={$appSession.isAdmin}
				class="icons bg-transparent tooltip tooltip-bottom text-sm"
				data-tip={$appSession.isAdmin
					? $t('database.delete_database')
					: $t('database.permission_denied_delete_database')}><DeleteIcon /></button
			>
		{/if}
	</nav>
{/if}
<slot />
