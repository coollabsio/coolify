<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/dashboard.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import { t } from '$lib/translations';
	import { get } from '$lib/api';
	import { onDestroy, onMount } from 'svelte';
	import Loading from './applications/[id]/logs/_Loading.svelte';

	export let applicationsCount: number;
	export let sourcesCount: number;
	export let destinationsCount: number;
	export let teamsCount: number;
	export let databasesCount: number;
	export let servicesCount: number;
	let loading = {
		usage: false
	};
	let usageInterval = null;
	let memoryWarning = false;
	let cpuWarning = false;
	let diskWarning = false;

	let trends = {
		memory: 'stable',
		cpu: 'stable',
		disk: 'stable'
	};
	let usage = {
		cpu: {
			load: [0, 0, 0],
			count: 0,
			usage: 0
		},
		memory: {
			totalMemMb: 0,
			freeMemMb: 0,
			usedMemMb: 0,
			freeMemPercentage: 0
		},
		disk: {
			freePercentage: 0,
			totalGb: 0,
			usedGb: 0
		}
	};
	async function getStatus() {
		if (loading.usage) return;
		try {
			loading.usage = true;
			const data = await get(`/dashboard.json?usage=true`);
			console.log(usage.memory.freeMemPercentage);
			if (data.memory.freeMemPercentage === usage.memory.freeMemPercentage) {
				trends.memory = 'stable';
			} else {
				if (data.memory.freeMemPercentage > usage.memory.freeMemPercentage) {
					trends.memory = 'up';
				} else {
					trends.memory = 'down';
				}
			}
			if (data.cpu.usage === usage.cpu.usage) {
				trends.cpu = 'stable';
			} else {
				if (data.cpu.usage > usage.cpu.usage) {
					trends.cpu = 'up';
				} else {
					trends.cpu = 'down';
				}
			}

			if (data.disk.freePercentage === usage.disk.freePercentage) {
				trends.disk = 'stable';
			} else {
				if (data.disk.freePercentage > usage.disk.freePercentage) {
					trends.disk = 'up';
				} else {
					trends.disk = 'down';
				}
			}

			usage = data;
			if (usage.memory.freeMemPercentage < 15) {
				memoryWarning = true;
			} else {
				memoryWarning = false;
			}
			if (usage.cpu.usage > 90) {
				cpuWarning = true;
			} else {
				cpuWarning = false;
			}
			if (usage.disk.freePercentage < 10) {
				diskWarning = true;
			} else {
				diskWarning = false;
			}
		} catch (error) {
		} finally {
			loading.usage = false;
		}
	}
	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		await getStatus();
		usageInterval = setInterval(async () => {
			await getStatus();
		}, 1000);
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.dashboard')}</div>
</div>
<div class="mt-10 pb-12 tracking-tight sm:pb-16">
	<div class="mx-auto max-w-4xl">
		<div class="title font-bold">Server Usage</div>

		<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<Loading />
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Total Memory</dt>
				<dd class="mt-1 text-3xl font-semibold text-white">
					{(usage?.memory.totalMemMb).toFixed(0)}
				</dd>
			</div>

			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Used Memory</dt>
				<dd class="mt-1 text-3xl font-semibold text-white ">
					{(usage?.memory.usedMemMb).toFixed(0)}
				</dd>
			</div>

			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6" class:bg-red-500={memoryWarning}>
				<dt class="truncate text-sm font-medium text-white">Free Memory</dt>
				<dd class="mt-1 flex items-center text-3xl font-semibold text-white">
					{usage?.memory.freeMemPercentage}%
					{#if trends.memory === 'stable' || trends.memory === '' || usage.memory.freeMemPercentage === 0}
						<span class="px-2 text-yellow-400"
							><svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-8 w-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path d="M5 9h14m-14 6h14" />
							</svg></span
						>
					{:else if trends.memory === 'up'}
						<span class="text-green-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="17" y1="7" x2="7" y2="17" />
								<polyline points="8 7 17 7 17 16" />
							</svg></span
						>
					{:else if trends.memory === 'down'}
						<span class="text-red-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="7" y1="7" x2="17" y2="17" />
								<polyline points="17 8 17 17 8 17" />
							</svg>
						</span>
					{/if}
				</dd>
			</div>
		</dl>
		<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Total CPUs</dt>
				<dd class="mt-1 text-3xl font-semibold text-white">
					{(usage?.cpu.count).toFixed(0)}
				</dd>
			</div>
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Load Average</dt>
				<dd class="mt-1 text-3xl font-semibold text-white">
					{usage?.cpu.load}
				</dd>
			</div>
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6" class:bg-red-500={cpuWarning}>
				<dt class="truncate text-sm font-medium text-white">CPU Usage</dt>
				<dd class="mt-1 flex items-center text-3xl  font-semibold text-white">
					{(usage?.cpu.usage).toFixed(0)}%
					{#if trends.cpu === 'stable' || trends.cpu === '' || usage.cpu.usage === 0}
						<span class="px-2 text-yellow-400"
							><svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-8 w-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path d="M5 9h14m-14 6h14" />
							</svg></span
						>
					{:else if trends.cpu === 'up'}
						<span class="text-green-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="17" y1="7" x2="7" y2="17" />
								<polyline points="8 7 17 7 17 16" />
							</svg></span
						>
					{:else if trends.cpu === 'down'}
						<span class="text-red-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="7" y1="7" x2="17" y2="17" />
								<polyline points="17 8 17 17 8 17" />
							</svg>
						</span>
					{/if}
				</dd>
			</div>
		</dl>
		<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Total Disk</dt>
				<dd class="mt-1 text-3xl font-semibold text-white">
					{usage?.disk.totalGb}GB
				</dd>
			</div>
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6">
				<dt class="truncate text-sm font-medium text-white">Used Disk</dt>
				<dd class="mt-1 text-3xl font-semibold text-white">
					{usage?.disk.usedGb}GB
				</dd>
			</div>
			<div class="overflow-hidden rounded-lg px-4 py-5 sm:p-6" class:bg-red-500={diskWarning}>
				<dt class="truncate text-sm font-medium text-white">Free Disk</dt>
				<dd class="mt-1 flex items-center text-3xl font-semibold text-white">
					{usage?.disk.freePercentage}%
					{#if trends.disk === 'stable' || trends.disk === '' || usage.disk.freePercentage === 0}
						<span class="px-2 text-yellow-400"
							><svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-8 w-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path d="M5 9h14m-14 6h14" />
							</svg></span
						>
					{:else if trends.disk === 'up'}
						<span class="text-green-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="17" y1="7" x2="7" y2="17" />
								<polyline points="8 7 17 7 17 16" />
							</svg></span
						>
					{:else if trends.disk === 'down'}
						<span class="text-red-500 px-2">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="w-8 h-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<line x1="7" y1="7" x2="17" y2="17" />
								<polyline points="17 8 17 17 8 17" />
							</svg>
						</span>
					{/if}
				</dd>
			</div>
		</dl>
		<div class="title pt-20 font-bold">Resources</div>
		<dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<a
				href="/applications"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-green-500 no-underline transition-all duration-100 hover:bg-green-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.applications')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{applicationsCount}
				</dd>
			</a>
			<a
				href="/destinations"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-sky-500 no-underline transition-all duration-100 hover:bg-sky-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.destinations')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{destinationsCount}
				</dd>
			</a>

			<a
				href="/sources"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-orange-500 no-underline transition-all duration-100 hover:bg-orange-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.git_sources')}</dt>
				<dd class="mt-1 text-3xl font-semibold">
					{sourcesCount}
				</dd>
			</a>
		</dl>
		<dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<a
				href="/databases"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-purple-500 no-underline transition-all duration-100 hover:bg-purple-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.databases')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{databasesCount}
				</dd>
			</a>

			<a
				href="/services"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-pink-500 no-underline transition-all duration-100 hover:bg-pink-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.services')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{servicesCount}
				</dd>
			</a>

			<a
				href="/iam"
				sveltekit:prefetch
				class="overflow-hidden rounded-lg px-4 py-5 text-cyan-500 no-underline transition-all duration-100 hover:bg-cyan-500 hover:text-white sm:p-6"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.teams')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{teamsCount}
				</dd>
			</a>
		</dl>
	</div>
</div>
