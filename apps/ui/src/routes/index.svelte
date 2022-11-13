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

	import { t } from '$lib/translations';	
	import { appSession } from '$lib/store';

	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import NewResource from './_NewResource.svelte';

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
	</div>
</ContextMenu>

<RightSidebar>
	<div slot="content">
		<div class="subtitle mb-4">Apps & Services</div>
		<SmList kind="app" things={sorted(appsAndServices)} />
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
