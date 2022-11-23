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
	import SearchBar from '$lib/components/SearchBar.svelte';
	import { beforeUpdate } from 'svelte';


	$: appsAndServices = applications.concat(services);
	$: canCreate = $appSession.isAdmin && (applications.length !== 0 ||
		destinations.length !== 0 || databases.length !== 0 ||
		services.length !== 0 || gitSources.length !== 0 ||
		destinations.length !== 0)

	// Filtering
	let filter = '';
	let filtered = {};
	let setVisible = (el:any) => {
		let txt = el.title ? el.title : el.name;
		el.visible = `${txt}`.toLowerCase().indexOf(filter.toLowerCase()) !== -1
		return el
	}
	let sorted = (items:any) => {
		let list = items.map(setVisible);
		return list.sort( (a:any,b:any) => a.name.localeCompare(b.name) )
	}
	beforeUpdate( () =>{
		filtered ={
			apps: sorted(appsAndServices),
			dbs: sorted(databases),
			gits: sorted(gitSources),
			destinations: sorted(destinations)
		}
	})

</script>

<ContextMenu>
	<h1 class="mr-4 text-2xl font-bold">{$t('index.dashboard')}</h1>
	<div slot="actions">
		<SearchBar bind:filter={filter} placeholder="Search Apps"/>
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
			<SmList kind="app" url='' things={filtered.apps}/>
		{:else}
			<AppsBlank>
				<NewResource><button class="btn btn-primary">Let's Get Started</button></NewResource>
  		</AppsBlank>
		{/if}
	</div>
	<div slot="sidebar">
		<div class="label">Databases</div>
		<SmList kind="database" url="/databases/" things={filtered.dbs}/>
		<br/>
		<div class="label">Git Sources</div>
		<SmList kind="source" url="/sources/" things={filtered.gits}/>
		<div class="label">Servers / Destinations</div>
		<SmList kind="server" url="/destinations/" things={filtered.destinations}/>
	</div>
</RightSidebar>
