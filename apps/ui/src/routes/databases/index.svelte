<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import {loadResources} from '$lib/resources';
	export const load: Load = loadResources;
</script>

<script lang="ts">
  export let databases:any;
  export let foundUnconfiguredDatabase:any;

	import PublicBadge from '$lib/components/badges/PublicBadge.svelte';
  import RefreshButton from '$lib/components/buttons/RefreshButton.svelte';
  import CleanUnconfiguredButton from '$lib/components/buttons/CleanUnconfiguredButton.svelte';
  import ContextMenu from "$lib/components/ContextMenu.svelte";
  import DatabaseIcons from '$lib/components/svg/databases/DatabaseIcons.svelte';
  import StatusBadge from '$lib/components/badges/StatusBadge.svelte';
	import TeamsBadge from '$lib/components/badges/TeamsBadge.svelte';
	import DestinationBadge from '$lib/components/badges/DestinationBadge.svelte';
  import LgCard from "$lib/components/cards/LgCard.svelte";
	import Grid3 from '$lib/components/grids/Grid3.svelte';
</script>

<ContextMenu>
	<div class="title">Databases</div>
  <div slot="actions">
    <RefreshButton things={databases} what='databases'/>
    <CleanUnconfiguredButton what='databases' unconfigured={foundUnconfiguredDatabase}/>
  </div>
</ContextMenu>

<Grid3>
  {#if databases.length > 0}
    {#each databases as database}
      <LgCard>
        <DatabaseIcons type={database.type} isAbsolute={true} />
        <div class="w-full flex flex-col">
          <a class="no-underline" href={`/databases/${database.id}`}>
            <h1 class="font-bold text-base truncate">{database.name}</h1>
          </a>
          <div class="h-10 text-xs">
            {#if database?.version}
              <h2 class="text-gray-500">{database?.version}</h2>
            {:else}
              <h2 class="text-red-500">Not version not configured</h2>
            {/if}
            <DestinationBadge name={database.destinationDocker?.name} thingId={database.id}/>
            <TeamsBadge teams={database.teams} thing={database}/>
          </div>
          <StatusBadge thing={database}/>
          {#if database.settings?.isPublic}
            <PublicBadge/>
          {/if}
        </div>
      </LgCard>
    {/each}
  {:else}
    <h1 class="">Nothing here.</h1>
  {/if}
</Grid3>
