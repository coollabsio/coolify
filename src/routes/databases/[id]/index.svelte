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
	export let database;
	export let settings;
	export let privatePort;
</script>

<div class="flex items-center space-x-1 p-6 text-2xl font-bold">
	<div class="md:max-w-64 hidden truncate tracking-tight md:block">
		{database.name}
	</div>
	<span class="arrow-right-applications hidden px-1 md:block">></span>
	<span class="pr-2">{database.type}</span>
</div>

<Databases bind:database {privatePort} {settings} />
