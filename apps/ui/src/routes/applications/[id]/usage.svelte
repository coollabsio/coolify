<script lang="ts">
	import { page } from '$app/stores';
	import { onDestroy, onMount } from 'svelte';
	import { get } from '$lib/api';
	import { status } from '$lib/store';

	const { id } = $page.params;

	let usageLoading = false;
	let usage = {
		MemUsage: 0,
		CPUPerc: 0,
		NetIO: 0
	};
	let usageInterval: any;

	async function getUsage() {
		if (usageLoading) return;
		if (!$status.application.isRunning) return;
		usageLoading = true;
		const data = await get(`/applications/${id}/usage`);
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

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Monitoring</div>
	</div>
</div>
<div class="mx-auto max-w-4xl px-6 py-4">
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
