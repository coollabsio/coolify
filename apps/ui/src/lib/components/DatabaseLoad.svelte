<script lang="ts">
  import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { onDestroy, onMount } from 'svelte';
  import { status } from '$lib/store';

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
		const data = await get(`/databases/${id}/usage`);
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

<div class="card mx-auto max-w-4xl p-5">
	<div class="text-center">
		<div class="stat w-64">
			<div class="stat-title">Memory: Used / Limit</div>
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