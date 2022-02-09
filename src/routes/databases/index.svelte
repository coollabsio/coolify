<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = `/databases.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	export let databases;
	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDB from '$lib/components/svg/databases/CouchDB.svelte';
	import MongoDB from '$lib/components/svg/databases/MongoDB.svelte';
	import MySQL from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSQL from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Databases</div>
	<a href="/new/database" class="add-icon bg-purple-600 hover:bg-purple-500">
		<svg
			class="w-6"
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
			><path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M12 6v6m0 0v6m0-6h6m-6 0H6"
			/></svg
		>
	</a>
</div>

<div class="flex flex-wrap justify-center">
	{#if !databases || databases.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No databases found</div>
		</div>
	{:else}
		{#each databases as database}
			<a href="/databases/{database.id}" class="no-underline p-2 w-96">
				<div class="box-selection relative hover:bg-purple-600 group">
					{#if database.type === 'clickhouse'}
						<Clickhouse isAbsolute />
					{:else if database.type === 'couchdb'}
						<CouchDB isAbsolute />
					{:else if database.type === 'mongodb'}
						<MongoDB isAbsolute />
					{:else if database.type === 'mysql'}
						<MySQL isAbsolute />
					{:else if database.type === 'postgresql'}
						<PostgreSQL isAbsolute />
					{:else if database.type === 'redis'}
						<Redis isAbsolute />
					{/if}
					<div class="font-bold text-xl text-center truncate">
						{database.name}
					</div>
					{#if !database.type}
						<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
							Configuration missing
						</div>
					{:else}
						<div class="text-center truncate">{database.type}</div>
					{/if}
				</div>
			</a>
		{/each}
	{/if}
</div>
