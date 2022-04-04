<script>
	export let database;
	import Clickhouse from './svg/databases/Clickhouse.svelte';
	import CouchDb from './svg/databases/CouchDB.svelte';
	import MongoDb from './svg/databases/MongoDB.svelte';
	import MySql from './svg/databases/MySQL.svelte';
	import PostgreSql from './svg/databases/PostgreSQL.svelte';
	import Redis from './svg/databases/Redis.svelte';
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
	{:else if database.type === 'postgresql'}
		<PostgreSql />
	{:else if database.type === 'redis'}
		<Redis />
	{/if}
</span>
