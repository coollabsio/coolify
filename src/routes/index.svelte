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
	import Trend from './_Trend.svelte';
	import { session } from '$app/stores';

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
		if ($session.teamId === '0') {
			await getStatus();
			usageInterval = setInterval(async () => {
				await getStatus();
			}, 1000);
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.dashboard')}</div>
</div>
<div class="mt-10 pb-12 tracking-tight sm:pb-16">
	<div class="mx-auto max-w-4xl">
		{#if $session.teamId === '0'}
			<div class="px-6 text-2xl font-bold">Server Usage</div>
			<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
				<Loading />
				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Total Memory</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{(usage?.memory.totalMemMb).toFixed(0)}<span class="text-sm">MB</span>
					</dd>
				</div>

				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Used Memory</dt>
					<dd class="mt-1 text-3xl font-semibold text-white ">
						{(usage?.memory.usedMemMb).toFixed(0)}<span class="text-sm">MB</span>
					</dd>
				</div>

				<div
					class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
					class:bg-red-500={memoryWarning}
				>
					<dt class="truncate text-sm font-medium text-white">Free Memory</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.memory.freeMemPercentage}<span class="text-sm">%</span>
						{#if !memoryWarning}
							<Trend trend={trends.memory} />
						{/if}
					</dd>
				</div>
			</dl>
			<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Total CPUs</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.cpu.count}
					</dd>
				</div>
				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Load Average (5/10/30mins)</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.cpu.load.join('/')}
					</dd>
				</div>
				<div
					class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
					class:bg-red-500={cpuWarning}
				>
					<dt class="truncate text-sm font-medium text-white">CPU Usage</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.cpu.usage}<span class="text-sm">%</span>
						{#if !cpuWarning}
							<Trend trend={trends.cpu} />
						{/if}
					</dd>
				</div>
			</dl>
			<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Total Disk</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.disk.totalGb}<span class="text-sm">GB</span>
					</dd>
				</div>
				<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
					<dt class="truncate text-sm font-medium text-white">Used Disk</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.disk.usedGb}<span class="text-sm">GB</span>
					</dd>
				</div>
				<div
					class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left"
					class:bg-red-500={diskWarning}
				>
					<dt class="truncate text-sm font-medium text-white">Free Disk</dt>
					<dd class="mt-1 text-3xl font-semibold text-white">
						{usage?.disk.freePercentage}<span class="text-sm">%</span>
						{#if !diskWarning}
							<Trend trend={trends.disk} />
						{/if}
					</dd>
				</div>
			</dl>
			<div class="px-6 pt-20 text-2xl font-bold">Resources</div>
		{/if}
		<dl class="mt-5 grid grid-cols-1 gap-5 px-2 sm:grid-cols-3">
			<a
				href="/applications"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-green-500 no-underline transition-all duration-100 hover:bg-green-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.applications')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{applicationsCount}
				</dd>
			</a>
			<a
				href="/destinations"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-sky-500 no-underline transition-all duration-100 hover:bg-sky-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.destinations')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{destinationsCount}
				</dd>
			</a>

			<a
				href="/sources"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-orange-500 no-underline transition-all duration-100 hover:bg-orange-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.git_sources')}</dt>
				<dd class="mt-1 text-3xl font-semibold">
					{sourcesCount}
				</dd>
			</a>
		</dl>
		<dl class="mt-5 grid grid-cols-1 gap-5 px-2 sm:grid-cols-3">
			<a
				href="/databases"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-purple-500 no-underline transition-all duration-100 hover:bg-purple-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.databases')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{databasesCount}
				</dd>
			</a>

			<a
				href="/services"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-pink-500 no-underline transition-all duration-100 hover:bg-pink-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.services')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{servicesCount}
				</dd>
			</a>

			<a
				href="/iam"
				sveltekit:prefetch
				class="overflow-hidden rounded px-4 py-5 text-center text-cyan-500 no-underline transition-all duration-100 hover:bg-cyan-500 hover:text-white sm:p-6 sm:text-left"
			>
				<dt class="truncate text-sm font-medium text-white">{$t('index.teams')}</dt>
				<dd class="mt-1 text-3xl font-semibold ">
					{teamsCount}
				</dd>
			</a>
		</dl>
	</div>
</div>
