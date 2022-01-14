<script lang="ts">
	export let database;
	export let versions;
	export let privatePort;
	import { page, session } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { enhance } from '$lib/form';

	import MySql from './_MySQL.svelte';
	import MongoDb from './_MongoDB.svelte';
	import PostgreSql from './_PostgreSQL.svelte';
	import Redis from './_Redis.svelte';
	import CouchDb from './_CouchDb.svelte';
	import { browser } from '$app/env';

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
	}
	let databaseUrl = generateUrl();

	function generateUrl() {
		return `${database.type}://${databaseDbUser}:${databaseDbUserPassword}@${
			browser
				? isPublic
					? window.location.hostname === 'localhost'
						? '127.0.0.1'
						: window.location.hostname
					: database.id
				: 'loading'
		}:${isPublic ? database.publicPort : privatePort}/${databaseDefault}`;
	}

	async function changeSettings(name) {
		const form = new FormData();
		if (name === 'isPublic') {
			isPublic = !isPublic;
		}
		form.append('isPublic', isPublic.toString());
		try {
			await fetch(`/databases/${id}/settings.json`, {
				method: 'POST',
				body: form
			});
			window.location.reload();
		} catch (e) {
			console.error(e);
		}
	}
</script>

<div class="max-w-4xl mx-auto px-6">
	<form
		action="/databases/{id}.json"
		use:enhance={{
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 200);
			},
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
		method="post"
		class="py-4"
	>
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Configurations</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:hover:bg-green-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="destination">Destination</label>
				<div class="col-span-2">
					{#if database.destinationDockerId}
						<a
							href={$session.isAdmin
								? `/databases/${id}/configuration/destination?from=/databases/${id}`
								: ''}
							class="no-underline"
							><span class="arrow-right-applications">></span><input
								value={database.destinationDocker.name}
								id="destination"
								disabled
								class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
							/></a
						>
					{/if}
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="name">Name</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						name="name"
						id="name"
						value={database.name}
						required
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="version">Version</label>
				<div class="col-span-2 ">
					<select name="version" id="version" bind:value={database.version}>
						<option value="Select a version" disabled selected>Select a version</option>
						{#each versions as version}
							<option value={version}>{version}</option>
						{/each}
					</select>
				</div>
			</div>
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center">
				<label for="host">Host</label>
				<div class="col-span-2 ">
					<CopyPasswordField
						placeholder="generated after start"
						isPasswordField={false}
						id="host"
						name="host"
						value={database.id}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="publicPort">Port</label>
				<div class="col-span-2">
					<CopyPasswordField
						placeholder="generate automatically"
						id="publicPort"
						name="publicPort"
						value={isPublic ? database.publicPort : privatePort}
					/>
				</div>
			</div>
			{#if database.type === 'mysql'}
				<MySql {database} />
			{:else if database.type === 'postgresql'}
				<PostgreSql {database} />
			{:else if database.type === 'mongodb'}
				<MongoDb {database} />
			{:else if database.type === 'redis'}
				<Redis {database} />
			{:else if database.type === 'couchdb'}
				<CouchDb {database} />
			{/if}
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="url">Connection String</label>
				<div class="col-span-2 ">
					<CopyPasswordField
						textarea={true}
						placeholder="generated after start"
						isPasswordField={false}
						id="url"
						name="url"
						value={databaseUrl}
					/>
				</div>
			</div>
		</div>
	</form>
	<div class="font-bold flex space-x-1 pb-5">
		<div class="text-xl tracking-tight mr-4">Features</div>
	</div>
	<div class="px-4 sm:px-6 pb-10">
		<ul class="mt-2 divide-y divide-warmGray-800">
			<Setting
				bind:setting={isPublic}
				on:click={() => changeSettings('isPublic')}
				title="Set it public"
				description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
			/>
		</ul>
	</div>
</div>
