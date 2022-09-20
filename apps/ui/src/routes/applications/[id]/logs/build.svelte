<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const response = await get(`/applications/${params.id}/logs/build?skip=0`);
			return {
				props: {
					application: stuff.application,
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let builds: any;
	export let application: any;
	export let buildCount: any;
	import { page } from '$app/stores';
	import { addToast, selectedBuildId } from '$lib/store';
	import BuildLog from './_BuildLog.svelte';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { changeQueryParams, dateOptions, errorNotification, asyncSleep } from '$lib/common';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import { day } from '$lib/dayjs';
	import { onDestroy, onMount } from 'svelte';
	const { id } = $page.params;

	let loadBuildLogsInterval: any = null;

	let skip = 0;
	let noMoreBuilds = buildCount < 5 || buildCount <= skip;
	let preselectedBuildId = $page.url.searchParams.get('buildId');
	if (preselectedBuildId) $selectedBuildId = preselectedBuildId;

	onMount(async () => {
		getBuildLogs();
		loadBuildLogsInterval = setInterval(() => {
			getBuildLogs();
		}, 2000);
		
	});
	onDestroy(() => {
		clearInterval(loadBuildLogsInterval);
	});
	async function getBuildLogs() {
		const response = await get(`/applications/${$page.params.id}/logs/build?skip=${skip}`);
		builds = response.builds;
	}
	
	async function loadMoreBuilds() {
		if (buildCount >= skip) {
			skip = skip + 5;
			noMoreBuilds = buildCount <= skip;
			try {
				const data = await get(`/applications/${id}/logs/build?skip=${skip}`);
				builds = data.builds
				return;
			} catch (error) {
				return errorNotification(error);
			}
		} else {
			noMoreBuilds = true;
		}
	}
	function loadBuild(build: any) {
		$selectedBuildId = build;
		return changeQueryParams($selectedBuildId);
	}
	async function resetQueue() {
		const sure = confirm(
			'It will reset all build queues for all applications. If something is queued, it will be canceled automatically. Are you sure? '
		);
		if (sure) {
			try {
				await post(`/internal/resetQueue`, {});
				addToast({
					message: 'Queue reset done.',
					type: 'success'
				});
				await asyncSleep(500);
				return window.location.reload();
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	function generateBadgeColors(status: string) {
		if (status === 'failed') {
			return 'text-red-500';
		} else if (status === 'running') {
			return 'text-yellow-300';
		} else if (status === 'success') {
			return 'text-green-500';
		} else if (status === 'canceled') {
			return 'text-orange-500';
		} else {
			return 'text-white';
		}
	}
</script>

<div class="block flex-row justify-start space-x-2 px-5 pt-6 sm:px-10 md:flex">
	<div class="mb-4 min-w-[16rem] space-y-2 md:mb-0 ">
		<button class="btn btn-sm text-xs w-full bg-error" on:click={resetQueue}
			>Reset Build Queue</button
		>
		<div class="top-4 md:sticky">
			{#each builds as build, index (build.id)}
				<div
					id={`building-${build.id}`}
					on:click={() => loadBuild(build.id)}
					class:rounded-tr={index === 0}
					class:rounded-br={index === builds.length - 1}
					class="flex cursor-pointer items-center justify-center py-4 no-underline transition-all duration-100 hover:bg-coolgray-300 hover:shadow-xl"
					class:bg-coolgray-200={$selectedBuildId === build.id}
				>
					<div class="flex-col px-2 text-center min-w-[10rem]">
						<div class="text-sm font-bold">
							{build.branch || application.branch}
						</div>
						<div class="text-xs">
							{build.type}
						</div>
						<div
							class={`badge badge-sm text-xs uppercase rounded bg-coolgray-300 border-none font-bold ${generateBadgeColors(
								build.status
							)}`}
						>
							{build.status}
						</div>
					</div>

					<div class="w-48 text-center text-xs">
						{#if build.status === 'running'}
							<div>
								<span class="font-bold text-xl"
									>{build.elapsed}s</span
								>
							</div>
						{:else if build.status !== 'queued'}
							<div>{day(build.updatedAt).utc().fromNow()}</div>
							<div>
								{$t('application.build.finished_in')}
								<span class="font-bold"
									>{day(build.updatedAt).utc().diff(day(build.createdAt)) / 1000}s</span
								>
							</div>
						{/if}
					</div>
				</div>
				<Tooltip triggeredBy={`#building-${build.id}`}
					>{new Intl.DateTimeFormat('default', dateOptions).format(new Date(build.createdAt)) +
						`\n`}</Tooltip
				>
			{/each}
		</div>
		{#if !noMoreBuilds}
			{#if buildCount > 5}
				<div class="flex space-x-2 pb-10">
					<button
						disabled={noMoreBuilds}
						class=" btn btn-sm w-full text-xs"
						on:click={loadMoreBuilds}>{$t('application.build.load_more')}</button
					>
				</div>
			{/if}
		{/if}
	</div>
	<div class="flex-1 md:w-96">
		{#if $selectedBuildId}
			{#key $selectedBuildId}
				<svelte:component this={BuildLog} />
			{/key}
		{/if}
	</div>
</div>
{#if buildCount === 0}
	<div class="text-center text-xl font-bold">{$t('application.build.no_logs')}</div>
{/if}
