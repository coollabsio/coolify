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

	import BuildLog from './_BuildLog.svelte';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import { changeQueryParams, dateOptions, errorNotification } from '$lib/common';

	let buildId: any;

	let skip = 0;
	let noMoreBuilds = buildCount < 5 || buildCount <= skip;
	const { id } = $page.params;
	let preselectedBuildId = $page.url.searchParams.get('buildId');
	if (preselectedBuildId) buildId = preselectedBuildId;

	async function updateBuildStatus({ detail }: { detail: any }) {
		const { status } = detail;
		if (status !== 'running') {
			try {
				const data = await get(`/applications/${id}/logs/build?buildId=${buildId}`);
				builds = builds.filter((build: any) => {
					if (build.id === data.builds[0].id) {
						build.status = data.builds[0].status;
						build.took = data.builds[0].took;
						build.since = data.builds[0].since;
					}
					return build;
				});
			} catch (error) {
				return errorNotification(error);
			}
		} else {
			builds = builds.filter((build: any) => {
				if (build.id === buildId) build.status = status;
				return build;
			});
		}
	}
	async function loadMoreBuilds() {
		if (buildCount >= skip) {
			skip = skip + 5;
			noMoreBuilds = buildCount >= skip;
			try {
				const data = await get(`/applications/${id}/logs/build?skip=${skip}`);
				builds = builds.concat(data.builds);
				return;
			} catch (error) {
				return errorNotification(error);
			}
		} else {
			noMoreBuilds = true;
		}
	}
	function loadBuild(build: any) {
		buildId = build;
		return changeQueryParams(buildId);
	}
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			{$t('application.build_logs')}
		</div>
		<span class="text-xs">{application.name} </span>
	</div>
</div>
<div class="block flex-row justify-start space-x-2 px-5 pt-6 sm:px-10 md:flex">
	<div class="mb-4 min-w-[16rem] space-y-2 md:mb-0 ">
		<div class="top-4 md:sticky">
			{#each builds as build, index (build.id)}
				<div
					data-tooltip={new Intl.DateTimeFormat('default', dateOptions).format(
						new Date(build.createdAt)
					) + `\n${build.status}`}
					on:click={() => loadBuild(build.id)}
					class:rounded-tr={index === 0}
					class:rounded-br={index === builds.length - 1}
					class="tooltip-top flex cursor-pointer items-center justify-center border-l-2  py-4 no-underline transition-all duration-100 hover:bg-coolgray-400 hover:shadow-xl "
					class:bg-coolgray-400={buildId === build.id}
					class:border-red-500={build.status === 'failed'}
					class:border-green-500={build.status === 'success'}
					class:border-yellow-500={build.status === 'running'}
				>
					<div class="flex-col px-2">
						<div class="text-sm font-bold">
							{build.branch || application.branch}
						</div>
						<div class="text-xs">
							{build.type}
						</div>
					</div>
					<div class="flex-1" />

					<div class="w-48 text-center text-xs">
						{#if build.status === 'running'}
							<div class="font-bold">{$t('application.build.running')}</div>
						{:else if build.status === 'queued'}
							<div class="font-bold">{$t('application.build.queued')}</div>
						{:else}
							<div>{build.since}</div>
							<div>
								{$t('application.build.finished_in')} <span class="font-bold">{build.took}s</span>
							</div>
						{/if}
					</div>
				</div>
			{/each}
		</div>
		{#if !noMoreBuilds}
			{#if buildCount > 5}
				<div class="flex space-x-2">
					<button disabled={noMoreBuilds} class="w-full" on:click={loadMoreBuilds}
						>{$t('application.build.load_more')}</button
					>
				</div>
			{/if}
		{/if}
	</div>
	<div class="flex-1 md:w-96">
		{#if buildId}
			{#key buildId}
				<svelte:component this={BuildLog} {buildId} on:updateBuildStatus={updateBuildStatus} />
			{/key}
		{/if}
	</div>
</div>
{#if buildCount === 0}
	<div class="text-center text-xl font-bold">{$t('application.build.no_logs')}</div>
{/if}
