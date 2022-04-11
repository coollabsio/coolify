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
					settings: stuff.settings,
					isRunning: stuff.isRunning
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
	import DatabaseLinks from '$lib/components/DatabaseLinks.svelte';
	export let database;
	export let settings;
	export let privatePort;
	export let isRunning;
</script>

<div class="flex items-center space-x-2 p-6 text-2xl font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
		</div>
		<span class="text-xs">{database.name}</span>
	</div>
	<DatabaseLinks {database} />
</div>

<Databases bind:database {privatePort} {settings} {isRunning} />
