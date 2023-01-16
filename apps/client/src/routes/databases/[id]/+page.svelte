<script lang="ts">
	import type { LayoutData } from './$types';

	export let data: LayoutData;
	let database = data.database.data.database;
	let privatePort = data.database.data.privatePort;

	import { page } from '$app/stores';
	import { onDestroy, onMount } from 'svelte';
	import Databases from './components/Databases/Databases.svelte';
	import { status, trpc } from '$lib/store';

	const { id } = $page.params;
	let loading = {
		usage: false
	};
	let usage = {
		MemUsage: 0,
		CPUPerc: 0,
		NetIO: 0
	};
	let usageInterval: any;

	async function getUsage() {
		if (loading.usage) return;
		if (!$status.database.isRunning) return;
		loading.usage = true;
		const { data } = await trpc.databases.usage.query({ id });
		usage = data.usage;
		loading.usage = false;
	}

	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		await getUsage();
		usageInterval = setInterval(async () => {
			await getUsage();
		}, 1500);
	});
</script>

<div class="mx-auto max-w-6xl p-5">
	<div class="text-center">
		<div class="stat w-64">
			<div class="stat-title">Used Memory / Memory Limit</div>
			<div class="stat-value text-xl">{usage?.MemUsage}</div>
		</div>

		<div class="stat w-64">
			<div class="stat-title">Used CPU</div>
			<div class="stat-value text-xl">{usage?.CPUPerc}</div>
		</div>

		<div class="stat w-64">
			<div class="stat-title">Network IO</div>
			<div class="stat-value text-xl">{usage?.NetIO}</div>
		</div>
	</div>
</div>
<Databases bind:database {privatePort} />
