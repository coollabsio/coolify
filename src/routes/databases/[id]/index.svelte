<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.database?.id) {
			return {
				props: {
					database: stuff.database
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
	import MySql from './_MySQL.svelte';

	export let database;
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl items-center">
	<div class="tracking-tight truncate md:max-w-64 md:block hidden">
		{database.name}
	</div>
	<span class="px-1 arrow-right-applications md:block hidden">></span>
	<span class="pr-2">{database.type}</span>
</div>

{#if database.type === 'mysql'}
	<MySql {database} />
{/if}
