<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';

	export const load: Load = async ({}) => {
		try {
			const data = await get('/resources');
			return {
				props: {
					...data
				},
				stuff: {
					...data
				}
			};
		} catch (error) {
			console.log(error);
			return {};
		}
	};
</script>

<script lang="ts">
	import { get } from '$lib/api';
	import Usage from '$lib/components/Usage.svelte';
	import { t } from '$lib/translations';

	export let applicationsCount: number = 0;
	export let sourcesCount: number = 0;
	export let destinationsCount: number = 0;
	export let teamsCount: number = 0;
	export let databasesCount: number = 0;
	export let servicesCount: number = 0;
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.dashboard')}</div>
</div>

<div class="mt-10 pb-12 tracking-tight sm:pb-16">
	<div class="mx-auto max-w-4xl">
		<Usage />
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
