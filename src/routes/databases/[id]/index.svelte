<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import Databases from './_Databases/_Databases.svelte';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.database?.id) {
			return {
				props: {
					database: stuff.database,
					versions: stuff.versions,
					privatePort: stuff.privatePort,
					settings: stuff.settings,
					isRunning: stuff.isRunning
				}
			};
		}
		const endpoint = `/databases/${params.id}.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	import DatabaseLinks from '$lib/components/DatabaseLinks.svelte';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { onDestroy, onMount } from 'svelte';
	export let database;
	export let settings;
	export let privatePort;
	export let isRunning;

	const { id } = $page.params;
	let usageLoading = false;
	let usage = {
		MemUsage: 0,
		CPUPerc: 0,
		NetIO: 0
	};
	let usageInterval;

	async function getUsage() {
		if (usageLoading) return;
		usageLoading = true;
		const data = await get(`/databases/${id}/usage.json`);
		usage = data.usage;
		usageLoading = false;
	}

	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		await getUsage();
		usageInterval = setInterval(async () => {
			await getUsage();
		}, 1000);
	});
</script>

<div class="flex items-center space-x-2 p-6 text-2xl font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
		</div>
		<span class="text-xs">{database.name}</span>
	</div>
	<DatabaseLinks {database} />
</div>

<div class="mx-auto max-w-4xl px-6 py-4">
	<div class="text-2xl font-bold">Database Usage</div>
	<div class="mx-auto">
		<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class=" text-sm font-medium text-white">Used Memory / Memory Limit</dt>
				<dd class="mt-1 text-xl font-semibold text-white">
					{usage?.MemUsage}
				</dd>
			</div>

			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class="truncate text-sm font-medium text-white">Used CPU</dt>
				<dd class="mt-1 text-xl font-semibold text-white ">
					{usage?.CPUPerc}
				</dd>
			</div>

			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class="truncate text-sm font-medium text-white">Network IO</dt>
				<dd class="mt-1 text-xl font-semibold text-white ">
					{usage?.NetIO}
				</dd>
			</div>
		</dl>
	</div>
</div>
<Databases bind:database {privatePort} {settings} {isRunning} />
