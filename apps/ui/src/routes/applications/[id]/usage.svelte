<script lang="ts">
	export let application: any;
	import { page } from '$app/stores';
	import { onDestroy, onMount } from 'svelte';
	import { get } from '$lib/api';
	import { status } from '$lib/store';
	import Tooltip from '$lib/components/Tooltip.svelte';

	const { id } = $page.params;
	let services: any = [];
	let selectedService: any = null;
	let usageLoading = false;
	let usage = {
		MemUsage: 0,
		CPUPerc: 0,
		NetIO: 0
	};
	let usageInterval: any;

	async function getUsage() {
		if (usageLoading) return;
		usageLoading = true;
		const data = await get(`/applications/${id}/usage/${selectedService}`);
		usage = data.usage;
		usageLoading = false;
	}
	function normalizeDockerServices(services: any[]) {
		const tempdockerComposeServices = [];
		for (const [name, data] of Object.entries(services)) {
			tempdockerComposeServices.push({
				name,
				data
			});
		}
		return tempdockerComposeServices;
	}
	async function selectService(service: any, init: boolean = false) {
		if (usageInterval) clearInterval(usageInterval);
		usageLoading = false;
		usage = {
			MemUsage: 0,
			CPUPerc: 0,
			NetIO: 0
		};
		selectedService = `${application.id}${service.name ? `-${service.name}` : ''}`;

		await getUsage();
		usageInterval = setInterval(async () => {
			await getUsage();
		}, 1000);
	}
	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		const response = await get(`/applications/${id}`);
		application = response.application;
		if (response.application.dockerComposeFile) {
			services = normalizeDockerServices(
				JSON.parse(response.application.dockerComposeFile).services
			);
		} else {
			services = [
				{
					name: ''
				}
			];
			await selectService('');
		}
	});
</script>

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Monitoring</div>
	</div>
</div>
<div class="flex gap-2 lg:gap-8 pb-4">
	{#each services as service}
		<button
			on:click={() => selectService(service, true)}
			class:bg-primary={selectedService ===
				`${application.id}${service.name ? `-${service.name}` : ''}`}
			class:bg-coolgray-200={selectedService !==
				`${application.id}${service.name ? `-${service.name}` : ''}`}
			class="w-full rounded p-5 hover:bg-primary font-bold"
		>
			{application.id}{service.name ? `-${service.name}` : ''}</button
		>
	{/each}
</div>
{#if selectedService}
	<div class="mx-auto max-w-4xl px-6 py-4 bg-coolgray-100 border border-coolgray-200 relative">
		{#if usageLoading}
			<button
				id="streaming"
				class="btn btn-sm bg-transparent border-none loading absolute top-0 left-0 text-xs"
			/>
			<Tooltip triggeredBy="#streaming">Streaming logs</Tooltip>
		{/if}
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
