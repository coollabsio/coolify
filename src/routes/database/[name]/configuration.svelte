<script>
	import { database } from '$store';
	import { page, session } from '$app/stores';
	import { request } from '$lib/api/request';
	import { fade } from 'svelte/transition';
	import { goto } from '$app/navigation';
	import MongoDb from '$components/Database/SVGs/MongoDb.svelte';
	import Postgresql from '$components/Database/SVGs/Postgresql.svelte';
	import Mysql from '$components/Database/SVGs/Mysql.svelte';
	import CouchDb from '$components/Database/SVGs/CouchDb.svelte';
	import Loading from '$components/Loading.svelte';
	import PasswordField from '$components/PasswordField.svelte';
	import { browser } from '$app/env';
	import { toast } from '@zerodevx/svelte-toast';

	async function backup() {
		try {
			await request(`/api/v1/databases/${$page.params.name}/backup`, $session, {body: {}});

			browser && toast.push(`Successfully created backup.`);
		} catch (error) {
			console.log(error);
			if (error.code === 501) {
				browser && toast.push(error.error);
			} else {
				browser && toast.push(`Error occured during database backup!`);
			}
		}
	}
	async function loadDatabaseConfig() {
		if ($page.params.name) {
			try {
				$database = await request(`/api/v1/databases/${$page.params.name}`, $session);
			} catch (error) {
				browser && goto(`/dashboard/databases`, { replaceState: true });
			}
		} else {
			browser && goto(`/dashboard/databases`, { replaceState: true });
		}
	}

</script>

{#await loadDatabaseConfig()}
	<Loading />
{:then}
	<div class="min-h-full text-white">
		<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
			<div>{$database.config.general.nickname}</div>
			<div class="px-4">
				{#if $database.config.general.type === 'mongodb'}
					<MongoDb customClass="w-8 h-8" />
				{:else if $database.config.general.type === 'postgresql'}
					<Postgresql customClass="w-8 h-8" />
				{:else if $database.config.general.type === 'mysql'}
					<Mysql customClass="w-8 h-8" />
				{:else if $database.config.general.type === 'couchdb'}
					<CouchDb customClass="w-8 h-8 fill-current text-red-600" />
				{/if}
			</div>
		</div>
	</div>
	<div class="text-left max-w-6xl mx-auto px-6" in:fade={{ duration: 100 }}>
		<div class="pb-2 pt-5 space-y-4">
			<div class="text-2xl font-bold border-gradient w-32">Database</div>
			<div class="flex items-center pt-4">
				<div class="font-bold w-64 text-warmGray-400">Connection string</div>
				{#if $database.config.general.type === 'mongodb'}
					<PasswordField
						value={`mongodb://${$database.envs.MONGODB_USERNAME}:${$database.envs.MONGODB_PASSWORD}@${$database.config.general.deployId}:27017/${$database.envs.MONGODB_DATABASE}`}
					/>
				{:else if $database.config.general.type === 'postgresql'}
					<PasswordField
						value={`postgresql://${$database.envs.POSTGRESQL_USERNAME}:${$database.envs.POSTGRESQL_PASSWORD}@${$database.config.general.deployId}:5432/${$database.envs.POSTGRESQL_DATABASE}`}
					/>
				{:else if $database.config.general.type === 'mysql'}
					<PasswordField
						value={`mysql://${$database.envs.MYSQL_USER}:${$database.envs.MYSQL_PASSWORD}@${$database.config.general.deployId}:3306/${$database.envs.MYSQL_DATABASE}`}
					/>
				{:else if $database.config.general.type === 'couchdb'}
					<PasswordField
						value={`http://${$database.envs.COUCHDB_USER}:${$database.envs.COUCHDB_PASSWORD}@${$database.config.general.deployId}:5984`}
					/>
				{:else if $database.config.general.type === 'clickhouse'}
					<!-- {JSON.stringify($database)} -->
					<!-- <textarea
            disabled
            class="w-full"
            value="{`postgresql://${$database.envs.POSTGRESQL_USERNAME}:${$database.envs.POSTGRESQL_PASSWORD}@${$database.config.general.deployId}:5432/${$database.envs.POSTGRESQL_DATABASE}`}"
          ></textarea> -->
				{/if}
			</div>
		</div>
		{#if $database.config.general.type === 'mongodb'}
			<div class="flex items-center">
				<div class="font-bold w-64 text-warmGray-400">Root password</div>
				<PasswordField value={$database.envs.MONGODB_ROOT_PASSWORD} />
			</div>
		{/if}
		<div class="pb-2 pt-5 space-y-4">
			<div class="text-2xl font-bold border-gradient w-32">Backup</div>
			<div class="pt-4">
				<button
					class="button hover:bg-warmGray-700 bg-warmGray-800 rounded p-2 font-bold "
					on:click={backup}>Download database backup</button
				>
			</div>
		</div>
	</div>
{/await}
