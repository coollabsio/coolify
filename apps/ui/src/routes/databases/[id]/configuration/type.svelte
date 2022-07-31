<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { database } = stuff;
			if (database?.type && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/database/${params.id}`
				};
			}
			const response = await get(`/databases/${params.id}/configuration/type`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let types: any;

	import { page } from '$app/stores';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDB from '$lib/components/svg/databases/CouchDB.svelte';
	import MongoDB from '$lib/components/svg/databases/MongoDB.svelte';
	import MariaDB from '$lib/components/svg/databases/MariaDB.svelte';
	import MySQL from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSQL from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';
	import EdgeDb from '$lib/components/svg/databases/EdgeDB.svelte';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	async function handleSubmit(type: any) {
		try {
			await post(`/databases/${id}/configuration/type`, { type });
			return await goto(from || `/databases/${id}/configuration/version`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('database.select_database_type')}</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each types as type}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(type.name)}>
				<button type="submit" class="box-selection relative text-xl font-bold hover:bg-purple-700">
					{#if type.name === 'clickhouse'}
						<Clickhouse isAbsolute />
					{:else if type.name === 'couchdb'}
						<CouchDB isAbsolute />
					{:else if type.name === 'mongodb'}
						<MongoDB isAbsolute />
					{:else if type.name === 'mariadb'}
						<MariaDB isAbsolute />
					{:else if type.name === 'mysql'}
						<MySQL isAbsolute />
					{:else if type.name === 'postgresql'}
						<PostgreSQL isAbsolute />
					{:else if type.name === 'redis'}
						<Redis isAbsolute />
					{:else if type.name === 'edgedb'}
						<EdgeDb isAbsolute />
					{/if}{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
