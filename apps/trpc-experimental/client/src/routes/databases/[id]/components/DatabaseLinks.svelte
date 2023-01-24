<script lang="ts">
	export let database: any;

	import Clickhouse from '$lib/components/icons/databases/Clickhouse.svelte';
	import CouchDb from '$lib/components/icons/databases/CouchDB.svelte';
	import EdgeDb from '$lib/components/icons/databases/EdgeDB.svelte';
	import MariaDb from '$lib/components/icons/databases/MariaDB.svelte';
	import MongoDb from '$lib/components/icons/databases/MongoDB.svelte';
	import MySql from '$lib/components/icons/databases/MySQL.svelte';
	import PostgreSql from '$lib/components/icons/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/icons/databases/Redis.svelte';
</script>

<span class="relative">
	{#if database.type === 'clickhouse'}
		<Clickhouse />
	{:else if database.type === 'couchdb'}
		<CouchDb />
	{:else if database.type === 'mongodb'}
		<MongoDb />
	{:else if database.type === 'mysql'}
		<MySql />
	{:else if database.type === 'mariadb'}
		<MariaDb />
	{:else if database.type === 'postgresql'}
		<PostgreSql />
	{:else if database.type === 'redis'}
		<Redis />
	{:else if database.type === 'edgedb'}
		<EdgeDb />
	{/if}
</span>
