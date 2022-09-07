<script lang="ts">
	export let database: any;

	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDb from '$lib/components/svg/databases/CouchDB.svelte';
	import EdgeDb from '$lib/components/svg/databases/EdgeDB.svelte';
	import MariaDb from '$lib/components/svg/databases/MariaDB.svelte';
	import MongoDb from '$lib/components/svg/databases/MongoDB.svelte';
	import MySql from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSql from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';
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
