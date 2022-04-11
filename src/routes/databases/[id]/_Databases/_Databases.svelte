<script lang="ts">
	export let database;
	export let privatePort;
	export let settings;
	export let isRunning;

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
	import { toast } from '@zerodevx/svelte-toast';

	const { id } = $page.params;

	let loading = false;
	let publicLoading = false;

	let isPublic = database.settings.isPublic || false;
	let appendOnly = database.settings.appendOnly;

	let databaseDefault;
	let databaseDbUser;
	let databaseDbUserPassword;

	generateDbDetails();

	function generateDbDetails() {
		databaseDefault = database.defaultDatabase;
		databaseDbUser = database.dbUser;
		databaseDbUserPassword = database.dbUserPassword;
		if (database.type === 'mongodb') {
			databaseDefault = '?readPreference=primary&ssl=false';
			databaseDbUser = database.rootUser;
			databaseDbUserPassword = database.rootUserPassword;
		} else if (database.type === 'redis') {
			databaseDefault = '';
			databaseDbUser = '';
		}
	}
	$: databaseUrl = generateUrl();

	function generateUrl() {
		return (databaseUrl = browser
			? `${database.type}://${
					databaseDbUser ? databaseDbUser + ':' : ''
			  }${databaseDbUserPassword}@${
					isPublic
						? settings.fqdn
							? getDomain(settings.fqdn)
							: window.location.hostname
						: database.id
			  }:${isPublic ? database.publicPort : privatePort}/${databaseDefault}`
			: 'Loading...');
	}

	async function changeSettings(name) {
		if (publicLoading || !isRunning) return;
		publicLoading = true;
		let data = {
			isPublic,
			appendOnly
		};
		if (name === 'isPublic') {
			data.isPublic = !isPublic;
		}
		if (name === 'appendOnly') {
			data.appendOnly = !appendOnly;
		}
		try {
			const { publicPort } = await post(`/databases/${id}/settings.json`, {
				isPublic: data.isPublic,
				appendOnly: data.appendOnly
			});
			isPublic = data.isPublic;
			appendOnly = data.appendOnly;
			databaseUrl = generateUrl();
			if (isPublic) {
				database.publicPort = publicPort;
			}
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			publicLoading = false;
		}
	}
	async function handleSubmit() {
		try {
			loading = true;
			await post(`/databases/${id}.json`, { ...database, isRunning });
			generateDbDetails();
			databaseUrl = generateUrl();
			toast.push('Settings saved.');
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="mx-auto max-w-4xl px-6">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="title">General</div>
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
			<div class="grid grid-cols-2 items-center">
				<label for="name" class="text-base font-bold text-stone-100">Name</label>
				<input
					readonly={!$session.isAdmin}
					name="name"
					id="name"
					bind:value={database.name}
					required
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="destination" class="text-base font-bold text-stone-100">Destination</label>
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

			<div class="grid grid-cols-2 items-center">
				<label for="version" class="text-base font-bold text-stone-100">Version</label>
				<input value={database.version} readonly disabled class="bg-transparent " />
			</div>
		</div>

		<div class="grid grid-flow-row gap-2 px-10 pt-2">
			<div class="grid grid-cols-2 items-center">
				<label for="host" class="text-base font-bold text-stone-100">Host</label>
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
			<div class="grid grid-cols-2 items-center">
				<label for="publicPort" class="text-base font-bold text-stone-100">Port</label>
				<CopyPasswordField
					placeholder="Generated automatically after set to public"
					id="publicPort"
					readonly
					disabled
					name="publicPort"
					value={publicLoading ? 'Loading...' : isPublic ? database.publicPort : privatePort}
				/>
			</div>
		</div>
		<div class="grid grid-flow-row gap-2">
			{#if database.type === 'mysql'}
				<MySql bind:database {isRunning} />
			{:else if database.type === 'postgresql'}
				<PostgreSql bind:database {isRunning} />
			{:else if database.type === 'mongodb'}
				<MongoDb bind:database {isRunning} />
			{:else if database.type === 'redis'}
				<Redis bind:database {isRunning} />
			{:else if database.type === 'couchdb'}
				<CouchDb {database} />
			{/if}
			<div class="grid grid-cols-2 items-center px-10 pb-8">
				<label for="url" class="text-base font-bold text-stone-100">Connection String</label>
				<CopyPasswordField
					textarea={true}
					placeholder="Generated automatically after start"
					isPasswordField={false}
					id="url"
					name="url"
					readonly
					disabled
					value={publicLoading || loading ? 'Loading...' : generateUrl()}
				/>
			</div>
		</div>
	</form>
	<div class="flex space-x-1 pb-5 font-bold">
		<div class="title">Features</div>
	</div>
	<div class="px-10 pb-10">
		<div class="grid grid-cols-2 items-center">
			<Setting
				loading={publicLoading}
				bind:setting={isPublic}
				on:click={() => changeSettings('isPublic')}
				title="Set it public"
				description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
				disabled={!isRunning}
			/>
		</div>
		{#if database.type === 'redis'}
			<div class="grid grid-cols-2 items-center">
				<Setting
					bind:setting={appendOnly}
					on:click={() => changeSettings('appendOnly')}
					title="Change append only mode"
					description="Useful if you would like to restore redis data from a backup.<br><span class='font-bold text-white'>Database restart is required.</span>"
				/>
			</div>
		{/if}
	</div>
</div>
