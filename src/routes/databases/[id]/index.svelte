<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import Databases from './_Databases/_Databases.svelte';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.database?.id) {
			return {
				props: {
					database: stuff.database,
					versions: stuff.versions,
					privatePort: stuff.privatePort,
					settings: stuff.settings
				}
			};
		}
		const endpoint = `/databases/${params.id}.json`;
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
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDb from '$lib/components/svg/databases/CouchDB.svelte';
	import MongoDb from '$lib/components/svg/databases/MongoDB.svelte';
	import MySql from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSql from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';

	export let database;
	export let settings;
	export let privatePort;
</script>

<div class="flex items-center space-x-2 p-6 text-2xl font-bold">
	<div class="md:max-w-64 hidden truncate tracking-tight md:block">
		{database.name}
	</div>
	<span class="relative">
		{#if database.type === 'clickhouse'}
			<Clickhouse />
		{:else if database.type === 'couchdb'}
			<CouchDb />
		{:else if database.type === 'mongodb'}
			<MongoDb />
		{:else if database.type === 'mysql'}
			<MySql />
		{:else if database.type === 'postgresql'}
			<PostgreSql />
		{:else if database.type === 'redis'}
			<Redis />
		{/if}
	</span>
</div>

<Databases bind:database {privatePort} {settings} />
