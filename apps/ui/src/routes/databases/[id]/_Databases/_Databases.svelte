<script lang="ts">
	export let database: any;
	export let privatePort: any;

	import { page } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';

	import MySql from './_MySQL.svelte';
	import MongoDb from './_MongoDB.svelte';
	import MariaDb from './_MariaDB.svelte';
	import PostgreSql from './_PostgreSQL.svelte';
	import Redis from './_Redis.svelte';
	import CouchDb from './_CouchDb.svelte';
	import EdgeDB from './_EdgeDB.svelte';
	import { post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession, status } from '$lib/store';
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
			const { publicPort } = await post(`/databases/${id}/settings`, {
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
			await post(`/databases/${id}`, { ...database, isRunning: $status.database.isRunning });
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
			<h1 class="title">{$t('general')}</h1>
			{#if $appSession.isAdmin}
				<button
					type="submit"
					class="btn btn-sm"
					class:loading={loading.main}
					class:bg-databases={!loading.main}
					disabled={loading.main}>{$t('forms.save')}</button
				>
			{/if}
		</div>
		<div class="grid gap-2 grid-cols-2 auto-rows-max lg:px-10 px-2">
			<label for="name">{$t('forms.name')}</label>
			<input
				class="w-full"
				readonly={!$appSession.isAdmin}
				name="name"
				id="name"
				bind:value={database.name}
				required
			/>
			<label for="destination">{$t('application.destination')}</label>
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
			<label for="host">{$t('forms.host')}</label>
			<CopyPasswordField
				placeholder={$t('forms.generated_automatically_after_start')}
				isPasswordField={false}
				readonly
				disabled
				id="host"
				name="host"
				value={database.id}
			/>
			<label for="publicPort">{$t('forms.port')}</label>
			<CopyPasswordField
				placeholder={$t('database.generated_automatically_after_set_to_public')}
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
					>{$t('database.connection_string')}
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
		<h1 class="title">{$t('application.features')}</h1>
	</div>
	<div class="grid gap-2 grid-cols-2 auto-rows-max lg:px-10 px-2">
		<Setting
			id="isPublic"
			loading={loading.public}
			bind:setting={$status.database.isPublic}
			on:click={() => changeSettings('isPublic')}
			title={$t('database.set_public')}
			description={$t('database.warning_database_public')}
			disabled={!$status.database.isRunning}
		/>
		{#if database.type === 'redis'}
			<Setting
				id="appendOnly"
				loading={loading.public}
				bind:setting={appendOnly}
				on:click={() => changeSettings('appendOnly')}
				title={$t('database.change_append_only_mode')}
				description={$t('database.warning_append_only')}
			/>
		{/if}
	</div>
</div>
