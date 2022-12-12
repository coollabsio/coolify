<script lang="ts">
	import * as Icons from '$lib/components/svg/databases';
	export let type: any;
	export let isAbsolute = false;
</script>

{#if type === 'mysql'}
	<Icons.MySQL {isAbsolute} />
{:else if type === 'postgresql'}
	<Icons.PostgreSQL {isAbsolute} />
{:else if type === 'mongodb'}
	<Icons.MongoDB {isAbsolute} />
{:else if type === 'mariadb'}
	<Icons.MariaDB {isAbsolute} />
{:else if type === 'redis'}
	<Icons.Redis {isAbsolute} />
{:else if type === 'couchdb'}
	<Icons.CouchDB {isAbsolute} />
{:else if type === 'edgedb'}
	<Icons.EdgeDB {isAbsolute} />
{/if}
