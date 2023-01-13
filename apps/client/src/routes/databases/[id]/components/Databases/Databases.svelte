<script lang="ts">
	export let database: any;
	export let privatePort: any;

	import { page } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';

	import MySql from './MySQL.svelte';
	import MongoDb from './MongoDB.svelte';
	import MariaDb from './MariaDB.svelte';
	import PostgreSql from './PostgreSQL.svelte';
	import Redis from './Redis.svelte';
	import CouchDb from './CouchDb.svelte';
	import EdgeDB from './EdgeDB.svelte';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession, status, trpc } from '$lib/store';
	import Explainer from '$lib/components/Explainer.svelte';

	const { id } = $page.params;

	let loading = {
		main: false,
		public: false
	};
	let publicUrl = '';
	let appendOnly = database.settings.appendOnly;

	let databaseDefault: any;
	let databaseDbUser: any;
	let databaseDbUserPassword: any;

	generateDbDetails();

	function generateDbDetails() {
		databaseDefault = database.defaultDatabase;
		databaseDbUser = database.dbUser;
		databaseDbUserPassword = database.dbUserPassword;
		if (database.type === 'mongodb' || database.type === 'edgedb') {
			if (database.type === 'mongodb') {
				databaseDefault = '?readPreference=primary&ssl=false';
			}
			databaseDbUser = database.rootUser;
			databaseDbUserPassword = database.rootUserPassword;
		} else if (database.type === 'redis') {
			databaseDefault = '';
			databaseDbUser = '';
		}
	}
	function generateUrl() {
		const ipAddress = () => {
			if ($status.database.isPublic) {
				if (database.destinationDocker.remoteEngine) {
					return database.destinationDocker.remoteIpAddress;
				}
				if ($appSession.ipv6) {
					return $appSession.ipv6;
				}
				if ($appSession.ipv4) {
					return $appSession.ipv4;
				}
				return '<Cannot determine public IP address>';
			} else {
				return database.id;
			}
		};
		const user = () => {
			if (databaseDbUser) {
				return databaseDbUser + ':';
			}
			return '';
		};
		const port = () => {
			if ($status.database.isPublic) {
				return database.publicPort;
			} else {
				return privatePort;
			}
		};
		publicUrl = `${
			database.type
		}://${user()}${databaseDbUserPassword}@${ipAddress()}:${port()}/${databaseDefault}`;
	}

	async function changeSettings(name: any) {
		if (name !== 'appendOnly') {
			if (loading.public || !$status.database.isRunning) return;
		}
		loading.public = true;
		let data = {
			isPublic: $status.database.isPublic,
			appendOnly
		};
		if (name === 'isPublic') {
			data.isPublic = !$status.database.isPublic;
		}
		if (name === 'appendOnly') {
			data.appendOnly = !appendOnly;
		}
		try {
			const { publicPort } = await trpc.databases.saveSettings.mutate({
				id,
				isPublic: data.isPublic,
				appendOnly: data.appendOnly
			});

			$status.database.isPublic = data.isPublic;
			appendOnly = data.appendOnly;
			if ($status.database.isPublic) {
				database.publicPort = publicPort;
			}
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.public = false;
		}
	}
	async function handleSubmit() {
		try {
			loading.main = true;
			await trpc.databases.save.mutate({
				id,
				...database,
				isRunning: $status.database.isRunning
			});
			generateDbDetails();
			addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.main = false;
		}
	}
</script>

<div class="mx-auto max-w-6xl p-4">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 items-center">
			<h1 class="title">General</h1>
			{#if $appSession.isAdmin}
				<button
					type="submit"
					class="btn btn-sm"
					class:loading={loading.main}
					class:bg-databases={!loading.main}
					disabled={loading.main}>Save</button
				>
			{/if}
		</div>
		<div class="grid gap-2 grid-cols-2 auto-rows-max lg:px-10 px-2">
			<label for="name">Name</label>
			<input
				class="w-full"
				readonly={!$appSession.isAdmin}
				name="name"
				id="name"
				bind:value={database.name}
				required
			/>
			<label for="destination">Destination</label>
			{#if database.destinationDockerId}
				<div class="no-underline">
					<input
						value={database.destinationDocker.name}
						id="destination"
						disabled
						readonly
						class="bg-transparent w-full"
					/>
				</div>
			{/if}
			<label for="version">Version / Tag</label>
			<a
				href={$appSession.isAdmin && !$status.database.isRunning
					? `/databases/${id}/configuration/version?from=/databases/${id}`
					: ''}
				class="no-underline"
			>
				<input
					class="w-full"
					value={database.version}
					readonly
					disabled={$status.database.isRunning || $status.database.initialLoading}
					class:cursor-pointer={!$status.database.isRunning}
				/></a
			>
			<label for="host">Host</label>
			<CopyPasswordField
				placeholder="Generated automatically after start"
				isPasswordField={false}
				readonly
				disabled
				id="host"
				name="host"
				value={database.id}
			/>
			<label for="publicPort">Port</label>
			<CopyPasswordField
				placeholder="Generated automatically after set to public"
				id="publicPort"
				readonly
				disabled
				name="publicPort"
				value={loading.public
					? 'Loading...'
					: $status.database.isPublic
					? database.publicPort
					: privatePort}
			/>
		</div>
		{#if database.type === 'mysql'}
			<MySql bind:database />
		{:else if database.type === 'postgresql'}
			<PostgreSql bind:database />
		{:else if database.type === 'mongodb'}
			<MongoDb bind:database />
		{:else if database.type === 'mariadb'}
			<MariaDb bind:database />
		{:else if database.type === 'redis'}
			<Redis bind:database />
		{:else if database.type === 'couchdb'}
			<CouchDb {database} />
		{:else if database.type === 'edgedb'}
			<EdgeDB {database} />
		{/if}
		<div class="flex flex-col space-y-2 mt-5">
			<div class="grid gap-2 grid-cols-2 auto-rows-max lg:px-10 px-2">
				<label for="url"
					>Connection String
					{#if !$status.database.isPublic && database.destinationDocker.remoteEngine}
						<Explainer
							explanation="You can only access the database with this URL if your application is deployed to the same Destination."
						/>
					{/if}</label
				>
				<button class="btn btn-sm" on:click|preventDefault={generateUrl}
					>Show Connection String</button
				>
			</div>
			<div class="lg:px-10 px-2">
				{#if publicUrl}
					<CopyPasswordField
						placeholder="Click on the button to generate URL"
						id="url"
						name="url"
						readonly
						disabled
						value={loading.public ? 'Loading...' : publicUrl}
					/>
				{/if}
			</div>
		</div>
	</form>
	<div class="flex space-x-1 pb-5 font-bold">
		<h1 class="title">Features</h1>
	</div>
	<div class="grid gap-2 grid-cols-2 auto-rows-max lg:px-10 px-2">
		<Setting
			id="isPublic"
			loading={loading.public}
			bind:setting={$status.database.isPublic}
			on:click={() => changeSettings('isPublic')}
			title="Set it Public"
			description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
			disabled={!$status.database.isRunning}
		/>
		{#if database.type === 'redis'}
			<Setting
				id="appendOnly"
				loading={loading.public}
				bind:setting={appendOnly}
				on:click={() => changeSettings('appendOnly')}
				title="Change append only mode"
				description="Useful if you would like to restore redis data from a backup.<br><span class=' text-white'>Database restart is required.</span>"
			/>
		{/if}
	</div>
</div>
