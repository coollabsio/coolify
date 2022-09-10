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

	let loading = false;
	let publicLoading = false;

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
	function generateUrl(): string {
		return `${database.type}://${
			databaseDbUser ? databaseDbUser + ':' : ''
		}${databaseDbUserPassword}@${
			$status.database.isPublic
				? database.destinationDocker.remoteEngine
					? database.destinationDocker.remoteIpAddress
					: $appSession.ipv4
				: database.id
		}:${$status.database.isPublic ? database.publicPort : privatePort}/${databaseDefault}`;
	}

	async function changeSettings(name: any) {
		if (name !== 'appendOnly') {
			if (publicLoading || !$status.database.isRunning) return;
		}
		publicLoading = true;
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
			publicLoading = false;
		}
	}
	async function handleSubmit() {
		try {
			loading = true;
			await post(`/databases/${id}`, { ...database, isRunning: $status.database.isRunning });
			generateDbDetails();
			addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="mx-auto max-w-4xl p-4">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex flex-col lg:flex-row justify-between lg:space-x-1 space-y-3 pb-5 lg:items-center items-start">
			<h1 class="title">{$t('general')}</h1>
			{#if $appSession.isAdmin}
				<button
					type="submit"
					class="btn btn-sm w-full lg:fit-content"
					class:loading
					class:bg-databases={!loading}
					disabled={loading}>{$t('forms.save')}</button
				>
			{/if}
		</div>
		<div class="grid gap-4 grid-cols-2 auto-rows-max lg:px-10">
			<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
			<input
				class="w-full"
				readonly={!$appSession.isAdmin}
				name="name"
				id="name"
				bind:value={database.name}
				required
			/>
			<label for="destination" class="text-base font-bold text-stone-100"
				>{$t('application.destination')}</label
			>
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
			<label for="version" class="text-base font-bold text-stone-100">Version / Tag</label>
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
			<label for="host" class="text-base font-bold text-stone-100">{$t('forms.host')}</label>
			<CopyPasswordField
				placeholder={$t('forms.generated_automatically_after_start')}
				isPasswordField={false}
				readonly
				disabled
				id="host"
				name="host"
				value={database.id}
			/>
			<label for="publicPort" class="text-base font-bold text-stone-100">{$t('forms.port')}</label>
			<CopyPasswordField
				placeholder={$t('database.generated_automatically_after_set_to_public')}
				id="publicPort"
				readonly
				disabled
				name="publicPort"
				value={publicLoading ? 'Loading...' : $status.database.isPublic ? database.publicPort : privatePort}
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
		<div class="flex flex-col space-y-3 mt-5">
			<div>
				<label for="url" class="text-base font-bold text-stone-100"
					>{$t('database.connection_string')}
					{#if !$status.database.isPublic && database.destinationDocker.remoteEngine}
						<Explainer
							explanation="You can only access the database with this URL if your application is deployed to the same Destination."
						/>
					{/if}</label
				>
			</div>
			<div class="lg:px-10">
				<CopyPasswordField
					textarea={true}
					placeholder={$t('forms.generated_automatically_after_start')}
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
		<h1 class="title">{$t('application.features')}</h1>
	</div>
	<div class="grid gap-4 grid-cols-2 auto-rows-max lg:px-10">
		<Setting
			id="isPublic"
			loading={publicLoading}
			bind:setting={$status.database.isPublic}
			on:click={() => changeSettings('isPublic')}
			title={$t('database.set_public')}
			description={$t('database.warning_database_public')}
			disabled={!$status.database.isRunning}
		/>
		{#if database.type === 'redis'}
			<Setting
				id="appendOnly"
				loading={publicLoading}
				bind:setting={appendOnly}
				on:click={() => changeSettings('appendOnly')}
				title={$t('database.change_append_only_mode')}
				description={$t('database.warning_append_only')}
			/>
		{/if}
	</div>
</div>
