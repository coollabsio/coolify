<script lang="ts">
	export let databases;
	import Clickhouse from '$lib/components/svg/databases/Clickhouse.svelte';
	import CouchDB from '$lib/components/svg/databases/CouchDB.svelte';
	import MongoDB from '$lib/components/svg/databases/MongoDB.svelte';
	import MySQL from '$lib/components/svg/databases/MySQL.svelte';
	import PostgreSQL from '$lib/components/svg/databases/PostgreSQL.svelte';
	import Redis from '$lib/components/svg/databases/Redis.svelte';
	import { post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';

	async function newDatabase() {
		const { id } = await post('/databases/new', {});
		return await goto(`/databases/${id}`, { replaceState: true });
	}
	const ownDatabases = databases.filter((database) => {
		if (database.teams[0].id === $session.teamId) {
			return database;
		}
	});
	const otherDatabases = databases.filter((database) => {
		if (database.teams[0].id !== $session.teamId) {
			return database;
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Databases</div>
	<div on:click={newDatabase} class="add-icon cursor-pointer bg-purple-600 hover:bg-purple-500">
		<svg
			class="w-6"
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
			><path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M12 6v6m0 0v6m0-6h6m-6 0H6"
			/></svg
		>
	</div>
</div>

<div class="flex flex-col flex-wrap justify-center">
	{#if !databases || ownDatabases.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No databases found</div>
		</div>
	{/if}
	{#if ownDatabases.length > 0 || otherDatabases.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownDatabases as database}
					<a href="/databases/{database.id}" class="w-96 p-2 no-underline">
						<div class="box-selection group relative hover:bg-purple-600">
							{#if database.type === 'clickhouse'}
								<Clickhouse isAbsolute />
							{:else if database.type === 'couchdb'}
								<CouchDB isAbsolute />
							{:else if database.type === 'mongodb'}
								<MongoDB isAbsolute />
							{:else if database.type === 'mysql'}
								<MySQL isAbsolute />
							{:else if database.type === 'postgresql'}
								<PostgreSQL isAbsolute />
							{:else if database.type === 'redis'}
								<Redis isAbsolute />
							{/if}
							<div class="truncate text-center text-xl font-bold">
								{database.name}
							</div>
							{#if $session.teamId === '0' && otherDatabases.length > 0}
								<div class="truncate text-center">{database.teams[0].name}</div>
							{/if}
							{#if !database.type}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>
			{#if otherDatabases.length > 0 && $session.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Databases</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherDatabases as database}
						<a href="/databases/{database.id}" class="w-96 p-2 no-underline">
							<div class="box-selection group relative hover:bg-purple-600">
								{#if database.type === 'clickhouse'}
									<Clickhouse isAbsolute />
								{:else if database.type === 'couchdb'}
									<CouchDB isAbsolute />
								{:else if database.type === 'mongodb'}
									<MongoDB isAbsolute />
								{:else if database.type === 'mysql'}
									<MySQL isAbsolute />
								{:else if database.type === 'postgresql'}
									<PostgreSQL isAbsolute />
								{:else if database.type === 'redis'}
									<Redis isAbsolute />
								{/if}
								<div class="truncate text-center text-xl font-bold">
									{database.name}
								</div>
								{#if $session.teamId === '0'}
									<div class="truncate text-center">{database.teams[0].name}</div>
								{/if}
								{#if !database.type}
									<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{:else}
									<div class="text-center truncate">{database.type}</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
