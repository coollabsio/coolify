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
					redirect: '/'
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
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession, status, isDeploymentEnabled } from '$lib/store';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { onDestroy, onMount } from 'svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import DatabaseLinks from './_DatabaseLinks.svelte';
	const { id } = $page.params;

	$status.database.isPublic = database.settings.isPublic || false;
	let statusInterval: any = false;
	let forceDelete = false;

	$isDeploymentEnabled = !$appSession.isAdmin;

	async function deleteDatabase(force: boolean) {
		const sure = confirm(`Are you sure you would like to delete '${database.name}'?`);
		if (sure) {
			$status.database.initialLoading = true;
			try {
				await del(`/databases/${database.id}`, { id: database.id, force });
				return await window.location.assign('/');
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.database.initialLoading = false;
			}
		}
	}
	async function stopDatabase() {
		const sure = confirm($t('database.confirm_stop', { name: database.name }));
		if (sure) {
			$status.database.initialLoading = true;
			$status.database.loading = true;
			try {
				await post(`/databases/${database.id}/stop`, {});
				$status.database.isPublic = false;
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.database.initialLoading = false;
				$status.database.loading = false;
				await getStatus();
			}
		}
	}
	async function startDatabase() {
		$status.database.initialLoading = true;
		$status.database.loading = true;
		try {
			await post(`/databases/${database.id}/start`, {});
		} catch (error) {
			return errorNotification(error);
		} finally {
			$status.database.initialLoading = false;
			$status.database.loading = false;
			await getStatus();
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
		$status.database.isRunning = false;
		$status.database.isExited = false;
		$status.database.loading = false;
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
	<nav class="header lg:flex-row flex-col-reverse">
		<div class="flex flex-row space-x-2 font-bold pt-10 lg:pt-0">
			<div class="flex flex-col items-center justify-center">
				<div class="title">
					{#if $page.url.pathname === `/databases/${id}`}
						Configurations
					{:else if $page.url.pathname === `/databases/${id}/logs`}
						Database Logs
					{:else if $page.url.pathname === `/databases/${id}/configuration/type`}
						Select a Database Type
					{:else if $page.url.pathname === `/databases/${id}/configuration/version`}
						Select a Database Version
					{:else if $page.url.pathname === `/databases/${id}/configuration/destination`}
						Select a Destination
					{/if}
				</div>
			</div>
			<DatabaseLinks {database} />
		</div>
		<div class="lg:block hidden flex-1" />
		<div class="flex flex-row flex-wrap space-x-3 justify-center lg:justify-start lg:py-0">
			{#if database.type && database.destinationDockerId && database.version}
				{#if $status.database.isExited}
					<a
						id="exited"
						href={!$status.database.isRunning ? `/databases/${id}/logs` : null}
						class="icons bg-transparent text-red-500 tooltip-error"
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
					<Tooltip triggeredBy="#exited">{'Service exited with an error!'}</Tooltip>
				{/if}
				{#if $status.database.initialLoading}
					<button class="icons flex animate-spin  duration-500 ease-in-out">
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
						id="stop"
						on:click={stopDatabase}
						type="submit"
						disabled={!$appSession.isAdmin}
						class="icons bg-transparent text-red-500"
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
					<Tooltip triggeredBy="#stop">{'Stop'}</Tooltip>
				{:else}
					<button
						id="start"
						on:click={startDatabase}
						type="submit"
						disabled={!$appSession.isAdmin}
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
					<Tooltip triggeredBy="#start">{'Start'}</Tooltip>
				{/if}
			{/if}
			<div class="border border-stone-700 h-8" />
			<a
				id="configuration"
				href="/databases/{id}"
				class="hover:text-yellow-500 rounded"
				class:text-yellow-500={$page.url.pathname === `/databases/${id}`}
				class:bg-coolgray-500={$page.url.pathname === `/databases/${id}`}
			>
				<button class="icons bg-transparent m text-sm disabled:text-red-500">
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
			<Tooltip triggeredBy="#configuration">{'Configuration'}</Tooltip>
			<div class="border border-stone-700 h-8" />
			<a
				id="databaselogs"
				href={$status.database.isRunning ? `/databases/${id}/logs` : null}
				class="hover:text-pink-500 rounded"
				class:text-pink-500={$page.url.pathname === `/databases/${id}/logs`}
				class:bg-coolgray-500={$page.url.pathname === `/databases/${id}/logs`}
			>
				<button disabled={!$status.database.isRunning} class="icons bg-transparent text-sm">
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
			<Tooltip triggeredBy="#databaselogs">{'Logs'}</Tooltip>
			{#if forceDelete}
				<button
					on:click={() => deleteDatabase(true)}
					type="submit"
					disabled={!$appSession.isAdmin}
					class:hover:text-red-500={$appSession.isAdmin}
					class="icons bg-transparent text-sm"
				>
					Force Delete</button
				>{:else}
				<button
					id="delete"
					on:click={() => deleteDatabase(false)}
					type="submit"
					disabled={!$appSession.isAdmin}
					class:hover:text-red-500={$appSession.isAdmin}
					class="icons bg-transparent text-sm"><DeleteIcon /></button
				>
			{/if}

			<Tooltip triggeredBy="#delete" placement="left">Delete</Tooltip>
		</div>
	</nav>
{/if}
<slot />
