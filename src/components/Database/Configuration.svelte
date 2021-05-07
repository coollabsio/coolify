<script>
	import { fade } from 'svelte/transition';
	import { toast } from '@zerodevx/svelte-toast';
	import MongoDb from './SVGs/MongoDb.svelte';
	import Postgresql from './SVGs/Postgresql.svelte';
	import Mysql from './SVGs/Mysql.svelte';
	import CouchDb from './SVGs/CouchDb.svelte';
	import { page, session } from '$app/stores';
	import { goto } from '$app/navigation';
	import { request } from '$lib/fetch';

	let type;
	let defaultDatabaseName;

	async function deploy() {
		try {
			await request(`/api/v1/databases/deploy`, $session, {
				body: {
					type,
					defaultDatabaseName
				}
			});

			toast.push('Database deployment queued.');
			goto(`/dashboard/databases`, { replaceState: true });
		} catch (error) {
			console.log(error);
		}
	}
</script>

<div class="text-center space-y-2 max-w-4xl mx-auto px-6" in:fade={{ duration: 100 }}>
	{#if $page.path === '/database/new'}
		<div class="flex justify-center space-x-4 font-bold pb-6">
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-green-600 p-2 rounded bg-warmGray-800 w-32"
				class:border-green-600={type === 'mongodb'}
				on:click={() => (type = 'mongodb')}
			>
				<div class="flex items-center justify-center  my-2">
					<MongoDb customClass="w-6" />
				</div>
				<div class="text-white">MongoDB</div>
			</div>
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-red-600 p-2 rounded bg-warmGray-800 w-32"
				class:border-red-600={type === 'couchdb'}
				on:click={() => (type = 'couchdb')}
			>
				<div class="flex items-center justify-center  my-2">
					<CouchDb customClass="w-12 text-red-600 fill-current" />
				</div>
				<div class="text-white">Couchdb</div>
			</div>
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-blue-600 p-2 rounded bg-warmGray-800 w-32"
				class:border-blue-600={type === 'postgresql'}
				on:click={() => (type = 'postgresql')}
			>
				<div class="flex items-center justify-center  my-2">
					<Postgresql customClass="w-12" />
				</div>
				<div class="text-white">PostgreSQL</div>
			</div>
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-orange-600 p-2 rounded bg-warmGray-800 w-32"
				class:border-orange-600={type === 'mysql'}
				on:click={() => (type = 'mysql')}
			>
				<div class="flex items-center justify-center">
					<Mysql customClass="w-10" />
				</div>
				<div class="text-white">MySQL</div>
			</div>

			<!-- <button
      class="button bg-gray-500 p-2 text-white hover:bg-yellow-500 cursor-pointer w-32"
      on:click="{() => (type = 'clickhouse')}"
      class:bg-yellow-500="{type === 'clickhouse'}"
    >
      Clickhouse
    </button> -->
		</div>
		{#if type}
			<div class="flex justify-center space-x-4 items-center">
				<label for="defaultDB">Default database</label>
				<input id="defaultDB" class="w-64" placeholder="random" bind:value={defaultDatabaseName} />

				<button
					class:bg-green-600={type === 'mongodb'}
					class:hover:bg-green-500={type === 'mongodb'}
					class:bg-blue-600={type === 'postgresql'}
					class:hover:bg-blue-500={type === 'postgresql'}
					class:bg-orange-600={type === 'mysql'}
					class:hover:bg-orange-500={type === 'mysql'}
					class:bg-red-600={type === 'couchdb'}
					class:hover:bg-red-500={type === 'couchdb'}
					class:bg-yellow-500={type === 'clickhouse'}
					class:hover:bg-yellow-400={type === 'clickhouse'}
					class="button p-2 w-32 text-white"
					on:click={deploy}>Deploy</button
				>
			</div>
		{/if}
	{/if}
</div>
