<script lang="ts">
	export let database;
	export let privatePort;
	export let settings;
	import { page, session } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';

	import MySql from './_MySQL.svelte';
	import MongoDb from './_MongoDB.svelte';
	import PostgreSql from './_PostgreSQL.svelte';
	import Redis from './_Redis.svelte';
	import CouchDb from './_CouchDb.svelte';
	import { browser } from '$app/env';
	import { post } from '$lib/api';
	import { getDomain } from '$lib/components/common';

	const { id } = $page.params;
	let loading = false;
	let isPublic = database.settings.isPublic || false;

	let databaseDefault = database.defaultDatabase;
	let databaseDbUser = database.dbUser;
	let databaseDbUserPassword = database.dbUserPassword;
	if (database.type === 'mongodb') {
		databaseDefault = '?readPreference=primary&ssl=false';
		databaseDbUser = database.rootUser;
		databaseDbUserPassword = database.rootUserPassword;
	} else if (database.type === 'redis') {
		databaseDefault = '';
	}
	let databaseUrl = generateUrl();

	function generateUrl() {
		return browser
			? `${database.type}://${databaseDbUser}:${databaseDbUserPassword}@${
					isPublic
						? settings.fqdn
							? getDomain(settings.fqdn)
							: window.location.hostname
						: database.id
			  }:${isPublic ? database.publicPort : privatePort}/${databaseDefault}`
			: 'Loading...';
	}

	async function changeSettings(name) {
		if (name === 'isPublic') {
			isPublic = !isPublic;
		}
		try {
			await post(`/databases/${id}/settings.json`, { isPublic });
			databaseUrl = generateUrl();
			return;
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/databases/${id}.json`, { ...database });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="mx-auto max-w-4xl px-6">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="mr-4 text-xl tracking-tight">General</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-purple-600={!loading}
					class:hover:bg-purple-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center">
				<label for="name">Name</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						name="name"
						id="name"
						bind:value={database.name}
						required
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="destination">Destination</label>
				<div class="col-span-2">
					{#if database.destinationDockerId}
						<div class="no-underline">
							<input
								value={database.destinationDocker.name}
								id="destination"
								disabled
								readonly
								class="bg-transparent "
							/>
						</div>
					{/if}
				</div>
			</div>

			<div class="grid grid-cols-3 items-center">
				<label for="version">Version</label>
				<div class="col-span-2 ">
					<input value={database.version} readonly disabled class="bg-transparent " />
				</div>
			</div>
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center">
				<label for="host">Host</label>
				<div class="col-span-2 ">
					<CopyPasswordField
						placeholder="Generated automatically after start"
						isPasswordField={false}
						readonly
						disabled
						id="host"
						name="host"
						value={database.id}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="publicPort">Port</label>
				<div class="col-span-2">
					<CopyPasswordField
						placeholder="Generated automatically after start"
						id="publicPort"
						readonly
						disabled
						name="publicPort"
						value={isPublic ? database.publicPort : privatePort}
					/>
				</div>
			</div>
			{#if database.type === 'mysql'}
				<MySql bind:database />
			{:else if database.type === 'postgresql'}
				<PostgreSql bind:database />
			{:else if database.type === 'mongodb'}
				<MongoDb {database} />
			{:else if database.type === 'redis'}
				<Redis {database} />
			{:else if database.type === 'couchdb'}
				<CouchDb bind:database />
			{/if}
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="url">Connection String</label>
				<div class="col-span-2 ">
					<CopyPasswordField
						textarea={true}
						placeholder="Generated automatically after start"
						isPasswordField={false}
						id="url"
						name="url"
						readonly
						disabled
						value={databaseUrl}
					/>
				</div>
			</div>
		</div>
	</form>
	<div class="flex space-x-1 pb-5 font-bold">
		<div class="mr-4 text-xl tracking-tight">Features</div>
	</div>
	<div class="px-4 pb-10 sm:px-6">
		<ul class="mt-2 divide-y divide-stone-800">
			<Setting
				bind:setting={isPublic}
				on:click={() => changeSettings('isPublic')}
				title="Set it public"
				description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
			/>
		</ul>
	</div>
</div>
