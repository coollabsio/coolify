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
	import { onDestroy, onMount } from 'svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import DatabaseLinks from './_DatabaseLinks.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import DeleteButton from '$lib/components/buttons/DeleteButton.svelte';
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
	<ContextMenu>
		<div class="flex flex-row">
			<DatabaseLinks {database} />
			<div class="title ml-2">
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
		<div slot="actions">
			<!-- Configuration -->
			<!-- logsico -->
			<DeleteButton action={() => deleteDatabase(forceDelete)} disabled={!$appSession.isAdmin}/>
		</div>
	</ContextMenu>
{/if}

<slot />
