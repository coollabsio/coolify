<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/databases`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let databases: any = [];
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { appSession } from '$lib/store';
	import DatabaseIcons from '$lib/components/svg/databases/DatabaseIcons.svelte';
	import { errorNotification } from '$lib/common';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';

	const from = $page.url.searchParams.get('from');

	let remoteDatabase = {
		name: null,
		type: null,
		host: null,
		port: null,
		user: null,
		password: null,
		database: null
	};

	const ownDatabases = databases.filter((database: any) => {
		if (database.teams[0].id === $appSession.teamId) {
			return database;
		}
	});
	const otherDatabases = databases.filter((database: any) => {
		if (database.teams[0].id !== $appSession.teamId) {
			return database;
		}
	});

	async function addCoolifyDatabase(database: any) {
		try {
			await post(`/applications/${$page.params.id}/configuration/database`, {
				databaseId: database.id,
				type: database.type
			});
			return window.location.assign(from || `/applications/${$page.params.id}/`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Select a Database</div>
</div>

<div class="flex-col justify-center mt-10 pb-12 sm:pb-16">
	{#if !databases || ownDatabases.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('database.no_databases_found')}</div>
		</div>
	{/if}
	{#if ownDatabases.length > 0 || otherDatabases.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownDatabases as database}
					<button on:click={() => addCoolifyDatabase(database)} class="p-2 no-underline">
						<div class="box-selection group relative hover:bg-purple-600">
							<DatabaseIcons type={database.type} isAbsolute={true} />
							<div class="truncate text-center text-xl font-bold">
								{database.name}
							</div>
							{#if $appSession.teamId === '0' && otherDatabases.length > 0}
								<div class="truncate text-center">{database.teams[0].name}</div>
							{/if}
							{#if database.destinationDocker?.name}
								<div class="truncate text-center">{database.destinationDocker.name}</div>
							{/if}
							{#if !database.type}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									{$t('application.configuration.configuration_missing')}
								</div>
							{/if}
						</div>
					</button>
				{/each}
			</div>
			{#if otherDatabases.length > 0 && $appSession.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-2xl font-bold text-center">Other Databases</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherDatabases as database}
						<a href="/databases/{database.id}" class="p-2 no-underline">
							<div class="box-selection group relative hover:bg-purple-600">
								<DatabaseIcons type={database.type} isAbsolute={true} />
								<div class="truncate text-center text-xl font-bold">
									{database.name}
								</div>
								{#if $appSession.teamId === '0'}
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

	<div class="mx-auto max-w-6xl p-6">
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="font-bold text-xl tracking-tight">Connect a Hosted / Remote Database</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="name" class="text-base font-bold text-stone-100">Name</label>
				<input name="name" id="name" required bind:value={remoteDatabase.name} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="type" class="text-base font-bold text-stone-100">Type</label>
				<input name="type" id="type" required bind:value={remoteDatabase.type} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="host" class="text-base font-bold text-stone-100">Host</label>
				<input name="host" id="host" required bind:value={remoteDatabase.host} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="port" class="text-base font-bold text-stone-100">Port</label>
				<input name="port" id="port" required bind:value={remoteDatabase.port} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="user" class="text-base font-bold text-stone-100">User</label>
				<input name="user" id="user" required bind:value={remoteDatabase.user} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="password" class="text-base font-bold text-stone-100">Password</label>
				<input name="password" id="password" required bind:value={remoteDatabase.password} />
			</div>
			<div class="mt-2 grid grid-cols-2 items-center px-4">
				<label for="database" class="text-base font-bold text-stone-100">Database Name</label>
				<input name="database" id="database" required bind:value={remoteDatabase.database} />
			</div>
		</div>
	</div>
</div>
