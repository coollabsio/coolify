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
	export let foundUnconfiguredApplication:any;
	export let foundUnconfiguredService:any;

	import { t } from '$lib/translations';	
	import { appSession } from '$lib/store';

	import AppsBlank from '$lib/screens/AppsBlank.svelte'
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import NewResource from './_NewResource.svelte';
	import CleanUnconfiguredButton from '$lib/components/buttons/CleanUnconfiguredButton.svelte';

	import RightSidebar from '$lib/components/RightSidebar.svelte';
	import SmList from '$lib/components/resources/SmList.svelte';

	let sorted = (items) => items.sort( (a,b) => a.name.localeCompare(b.name) )
	$: appsAndServices = applications.concat(services)
	$: canCreate = $appSession.isAdmin && (applications.length !== 0 || 
		destinations.length !== 0 || databases.length !== 0 || 
		services.length !== 0 || gitSources.length !== 0 || 
		destinations.length !== 0)
</script>

<ContextMenu>
	<h1 class="mr-4 text-2xl font-bold">{$t('index.dashboard')}</h1>
	<div slot="actions">
		{#if canCreate}
			<NewResource />
		{/if}
		<CleanUnconfiguredButton what='applications' unconfigured={foundUnconfiguredApplication}/>
		<CleanUnconfiguredButton what='services' unconfigured={foundUnconfiguredService}/>
	</div>
</ContextMenu>

<RightSidebar>
	<div slot="content">
		<div class="subtitle mb-4">Apps & Services</div>
		{#if appsAndServices.length > 0}
			<SmList kind="app" things={sorted(appsAndServices)} />
		{:else}
			<AppsBlank>
				<NewResource><button class="btn btn-primary">Let's Get Started</button></NewResource>
  		</AppsBlank>
		{/if}
	</div>
	<div slot="sidebar">
		<div class="label">Databases</div>
		<SmList kind="database" url="/databases/" things={sorted(databases)} />
		<br/>
		<div class="label">Git Sources</div>
		<SmList kind="source" url="/sources/" things={sorted(gitSources)} />
		<div class="label">Servers / Destinations</div>
		<SmList kind="server" url="/destinations/" things={sorted(destinations)} />
	</div>
</RightSidebar>
