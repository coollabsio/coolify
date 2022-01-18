<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { database } = stuff;
		if (database?.type && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/databases/${params.id}`
			};
		}
		const endpoint = `/databases/${params.id}/configuration/type.json`;
		const res = await fetch(endpoint);

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
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let types;
	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDB from '$lib/components/svg/databases/CouchDB.svelte';
	import MongoDB from '$lib/components/svg/databases/MongoDB.svelte';
	import MySQL from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSQL from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';
	import { goto } from '$app/navigation';
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Database type</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each types as type}
		<div class="p-2">
			<form
				action="/databases/{id}/configuration/type.json"
				method="post"
				use:enhance={{
					result: async () => {
						goto(from || `/databases/${id}/configuration/version` || `/databases/${id}`);
						// window.location.assign(from || `/databases/${id}`);
					}
				}}
			>
				<input class="hidden" name="type" value={type.name} />
				<button type="submit" class="box-selection text-xl font-bold hover:bg-purple-700 relative">
					{#if type.name === 'clickhouse'}
						<Clickhouse />
					{:else if type.name === 'couchdb'}
						<CouchDB />
					{:else if type.name === 'mongodb'}
						<MongoDB />
					{:else if type.name === 'mysql'}
						<MySQL />
					{:else if type.name === 'postgresql'}
						<PostgreSQL />
					{:else if type.name === 'redis'}
						<Redis />
					{/if}{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
