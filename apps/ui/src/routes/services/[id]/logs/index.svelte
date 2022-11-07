<script lang="ts">
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { onDestroy, onMount } from 'svelte';

	let service: any = {};
	let template: any = null;
	let logsLoading = false;
	let loadLogsInterval: any = null;
	let logs: any = [];
	let lastLog: any = null;
	let followingInterval: any;
	let followingLogs: any;
	let logsEl: any;
	let position = 0;
	let selectedService: any = null;
	let noContainer = false;

	const { id } = $page.params;

	onMount(async () => {
		const response = await get(`/services/${id}`);
		template = response.template;
		service = response.service;
	});

	onDestroy(() => {
		clearInterval(loadLogsInterval);
		clearInterval(followingInterval);
	});

	async function loadLogs() {
		if (logsLoading) return;
		try {
			const newLogs: any = await get(
				`/services/${id}/logs/${selectedService}?since=${lastLog?.split(' ')[0] || 0}`
			);
			if (newLogs.noContainer) {
				noContainer = true;
				logs = [];
				if (logs.length > 0) {
					clearInterval(loadLogsInterval);
					selectedService = null;
				}
				return;
			} else {
				noContainer = false;
			}
			if (newLogs?.logs && newLogs.logs[newLogs.logs.length - 1] !== logs[logs.length - 1]) {
				logs = logs.concat(newLogs.logs);
				lastLog = newLogs.logs[newLogs.logs.length - 1];
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	function detect() {
		if (position < logsEl.scrollTop) {
			position = logsEl.scrollTop;
		} else {
			if (followingLogs) {
				clearInterval(followingInterval);
				followingLogs = false;
			}
			position = logsEl.scrollTop;
		}
	}

	function followBuild() {
		followingLogs = !followingLogs;
		if (followingLogs) {
			followingInterval = setInterval(() => {
				logsEl.scrollTop = logsEl.scrollHeight;
				window.scrollTo(0, document.body.scrollHeight);
			}, 1000);
		} else {
			clearInterval(followingInterval);
		}
	}
	async function selectService(service: any, init: boolean = false) {
		if (loadLogsInterval) clearInterval(loadLogsInterval);
		if (followingInterval) clearInterval(followingInterval);

		logs = [];
		lastLog = null;
		followingLogs = false;

		selectedService = service;
		loadLogs();
		loadLogsInterval = setInterval(() => {
			loadLogs();
		}, 1000);
	}
</script>

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Service Logs</div>
	</div>
</div>

{#if template}
	<div class="grid grid-cols-3 gap-2 lg:gap-8 pb-4">
		{#each Object.keys(template) as service}
			<button
				on:click={() => selectService(service, true)}
				class:bg-primary={selectedService === service}
				class:bg-coolgray-200={selectedService !== service}
				class="w-full rounded p-5 hover:bg-primary font-bold"
			>
				{#if template[service].name}
					{template[service].name || ''} <br /><span class="text-xs">({service})</span>
				{:else}
					<span>{service}</span>
				{/if}
			</button>
		{/each}
	</div>
{:else}
	<div class="w-full flex justify-center font-bold text-xl">Loading components...</div>
{/if}

{#if selectedService}
	<div class="flex flex-row justify-center space-x-2">
		{#if logs.length === 0}
			{#if noContainer}
				<div class="text-xl font-bold tracking-tighter">Container not found / exited.</div>
			{/if}
		{:else}
			<div class="relative w-full">
				<div class="flex justify-start sticky space-x-2 pb-2">
					<button on:click={followBuild} class="btn btn-sm " class:bg-coollabs={followingLogs}>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="w-6 h-6 mr-2"
							viewBox="0 0 24 24"
							stroke-width="1.5"
							stroke="currentColor"
							fill="none"
							stroke-linecap="round"
							stroke-linejoin="round"
						>
							<path stroke="none" d="M0 0h24v24H0z" fill="none" />
							<circle cx="12" cy="12" r="9" />
							<line x1="8" y1="12" x2="12" y2="16" />
							<line x1="12" y1="8" x2="12" y2="16" />
							<line x1="16" y1="12" x2="12" y2="16" />
						</svg>
						{followingLogs ? 'Following Logs...' : 'Follow Logs'}
					</button>
					{#if loadLogsInterval}
						<button id="streaming" class="btn btn-sm bg-transparent border-none loading"
							>Streaming logs</button
						>
					{/if}
				</div>
				<div
					bind:this={logsEl}
					on:scroll={detect}
					class="font-mono w-full bg-coolgray-100 border border-coolgray-200 p-5 overflow-x-auto overflox-y-auto max-h-[80vh] rounded mb-20 flex flex-col scrollbar-thumb-coollabs scrollbar-track-coolgray-200 scrollbar-w-1"
				>
					{#each logs as log}
						<p>{log + '\n'}</p>
					{/each}
				</div>
			</div>
		{/if}
	</div>
{/if}
