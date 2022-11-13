<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import {loadResources} from '$lib/resources';
	export const load: Load = loadResources;
</script>

<script lang="ts">
	// From loadResources
	export let applications:any;
	export let databases:any;
	export let destinations:any;
	export let gitSources:any;
	export let services:any;

	// export let foundUnconfiguredApplication: boolean;
	// export let foundUnconfiguredService: boolean;
	// export let foundUnconfiguredDatabase: boolean;

	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	
	import { appSession, search, resources } from '$lib/store';

	import ApplicationsIcons from '$lib/components/svg/applications/ApplicationIcons.svelte';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import { dev } from '$app/env';
	import NewResource from './_NewResource.svelte';
	import { onMount } from 'svelte';
	// import AppsBlank from '$lib/screens/AppsBlank.svelte';
	// import AppsNothingFound from '$lib/screens/AppsNothingFound.svelte';
	// import {noInitialStatus,setInitials, doSearch, filtered} from '$lib/api/dashboard.js';
	// import {getStatus,numberOfGetStatus,status,refreshStatusServices,refreshStatusApplications} from '$lib/api/status';
	// import {cleanupApplications, cleanupServices,cleanupDatabases} from '$lib/api/cleanup';
	import RightSidebar from '$lib/components/RightSidebar.svelte';
	import SmList from '$lib/components/resources/SmList.svelte';
	

	let loading = { applications: false, services: false, databases: false };
	let searchInput: HTMLInputElement;
	// doSearch();
	onMount(() => {
		// setTimeout(() => {searchInput.focus();}, 100);
		// setInitials()
	});
	$: appsAndServices = applications.concat(services)
</script>

<ContextMenu>
	<h1 class="mr-4 text-2xl font-bold">{$t('index.dashboard')}</h1>
	<div slot="actions">
		{#if $appSession.isAdmin && (applications.length !== 0 || destinations.length !== 0 || databases.length !== 0 || services.length !== 0 || gitSources.length !== 0 || destinations.length !== 0)}
			<NewResource />
		{/if}
</div>
</ContextMenu>

<RightSidebar>
	<div slot="content">
		<div class="subtitle mb-4">Apps & Services</div>
		<SmList kind="app" things={appsAndServices.sort( (a,b) => a.name.localeCompare(b.name) )} />
	</div>
	<div slot="sidebar">
		<div class="label">Databases</div>
		<SmList kind="database" url="/databases/" things={databases.sort( (a,b) => a.name.localeCompare(b.name) )} />
		<br/>
		<div class="label">Git Sources</div>
		<SmList kind="source" url="/sources/" things={gitSources.sort( (a,b) => a.name.localeCompare(b.name) )} />
		<div class="label">Servers / Destinations</div>
		<SmList kind="server" url="/destinations/" things={destinations.sort( (a,b) => a.name.localeCompare(b.name) )} />
	</div>
</RightSidebar>
