<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	import Services from './_Services/_Services.svelte';
	import { get } from '$lib/api';
	import { page } from '$app/stores';
	import { status } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import ServiceLinks from './_ServiceLinks.svelte';

	export let service: any;
	export let readOnly: any;
	export let settings: any;

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
		if (!$status.service.isRunning) return;
		loading.usage = true;
		const data = await get(`/services/${id}/usage`);
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
		}, 1000);
	});
</script>

{#if $status.service.isRunning}
	<div class="mx-auto max-w-6xl px-6 lg:my-0 my-4 lg:pt-0 pt-4 rounded">
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
{/if}
<Services bind:service bind:readOnly bind:settings />
