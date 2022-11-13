<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import {loadResources} from '$lib/resources';
	export const load: Load = loadResources;
</script>

<script lang="ts">
  export let databases:any;
  export let foundUnconfiguredDatabase:any;
  let loading = {database: false};
  let status = {}

  import {getStatus,cleanupDatabases,refreshStatusDatabases} from '$lib/api/dashboard';
  import {noInitialStatus} from '$lib/api/dashboard.js';
	import PublicBadge from '$lib/components/badges/PublicBadge.svelte';

  import ContextMenu from "$lib/components/ContextMenu.svelte";
  import DatabaseIcons from '$lib/components/svg/databases/DatabaseIcons.svelte';
</script>

<ContextMenu>
	<div class="title">Databases</div>
  <div slot="actions">
    <button class="btn btn-sm btn-primary" on:click={refreshStatusDatabases}
      >{noInitialStatus.databases ? 'Load Status' : 'Refresh Status'}</button
    >
    {#if foundUnconfiguredDatabase}
      <button
        class="btn btn-sm"
        class:loading={loading.databases}
        disabled={loading.databases}
        on:click={cleanupDatabases}>Cleanup Unconfigured Resources</button
      >
    {/if}
  </div>
</ContextMenu>

<div
  class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
>
  {#if databases.length > 0}
    {#each databases as database}
      <a class="no-underline mb-5" href={`/databases/${database.id}`}>
        <div
          class="w-full rounded p-5 bg-coolgray-200 hover:bg-databases indicator duration-150"
        >
          {#await getStatus(database)}
            <span class="indicator-item badge bg-yellow-300 badge-sm" />
          {:then}
            {#if !noInitialStatus.databases}
              {#if status[database.id] === 'loading'}
                <span class="indicator-item badge bg-yellow-300 badge-sm" />
              {:else if status[database.id] === 'running'}
                <span class="indicator-item badge bg-success badge-sm" />
              {:else}
                <span class="indicator-item badge bg-error badge-sm" />
              {/if}
            {/if}
          {/await}
          <div class="w-full flex flex-row">
            <DatabaseIcons type={database.type} isAbsolute={true} />
            <div class="w-full flex flex-col">
              <div class="h-10">
                <h1 class="font-bold text-base truncate">{database.name}</h1>
                <div class="h-10 text-xs">
                  {#if database?.version}
                    <h2 class="">{database?.version}</h2>
                  {:else}
                    <h2 class="text-red-500">Not version not configured</h2>
                  {/if}
                  {#if database.destinationDocker?.name}
                    <div class="truncate">{database.destinationDocker?.name}</div>
                  {/if}
                  {#if database.teams.length > 0 && database.teams[0]?.name}
                    <div class="truncate">{database.teams[0]?.name}</div>
                  {/if}
                </div>
              </div>
              <div class="flex justify-end items-end space-x-2 h-10">
                {#if database.settings?.isPublic}
                  <PublicBadge/>
                {/if}
              </div>
            </div>
          </div>
        </div>
      </a>
    {/each}
  {:else}
    <h1 class="">Nothing here.</h1>
  {/if}
</div>
