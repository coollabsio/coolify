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
				props: {database,versions,privatePort},
				stuff: {database,versions,privatePort,settings}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let settings, iat, token, versions, privatePort, database;

	import { del, get, post } from '$lib/api';
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession, status } from '$lib/store';
	import DatabaseLinks from './_DatabaseLinks.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import DeleteButton from '$lib/components/buttons/DeleteButton.svelte';
	import ConfigurationsIcoButton from '$lib/components/buttons/ConfigurationsIcoButton.svelte';
	import LogsIcoButton from '$lib/components/buttons/LogsIcoButton.svelte';
	import ThingStatusToggler from '$lib/components/buttons/ThingStatusToggler.svelte';
	const { id } = $page.params;

	

	$status.database.isPublic = database.settings.isPublic || false;
	
	let forceDelete = false;

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
		<div slot="actions" class="flex flex-row">
			<ThingStatusToggler {id} 
				what='databases' 
				thing={database} 
				valid={database.type && database.destinationDockerId && database.version && database.defaultDatabase}
			/>
			<ConfigurationsIcoButton {id} what="databases"/>
			<LogsIcoButton {id} what="databases"/>
			<DeleteButton action={() => deleteDatabase(forceDelete)} disabled={!$appSession.isAdmin}/>
		</div>
	</ContextMenu>
{/if}

<slot />
