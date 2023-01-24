<script lang="ts">
	import type { PageData } from '../build/$types';

	export let data: PageData;
	console.log(data);
	let builds = data.builds;
	const application = data.application.data;
	const buildCount = data.buildCount;

	import { page } from '$app/stores';
	import { addToast, selectedBuildId, trpc } from '$lib/store';
	import BuildLog from './BuildLog.svelte';
	import { changeQueryParams, dateOptions, errorNotification, asyncSleep } from '$lib/common';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import { day } from '$lib/dayjs';
	import { onDestroy, onMount } from 'svelte';
	const { id } = $page.params;
	let debug = application.settings.debug;
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
		const response = await trpc.applications.getBuilds.query({ id, skip });
		builds = response.builds;
	}

	async function loadMoreBuilds() {
		if (buildCount >= skip) {
			skip = skip + 5;
			noMoreBuilds = buildCount <= skip;
			try {
				const data = await trpc.applications.getBuilds.query({ id, skip });
				builds = data.builds;
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
				await trpc.applications.resetQueue.mutate();
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
	async function changeSettings(name: any) {
		if (name === 'debug') {
			debug = !debug;
		}
		try {
			trpc.applications.saveSettings.mutate({
				id,
				debug
			});
			return addToast({
				message: 'Settings saved.',
				type: 'success'
			});
		} catch (error) {
			if (name === 'debug') {
				debug = !debug;
			}

			return errorNotification(error);
		}
	}
</script>

<div class="mx-auto w-full lg:px-0 px-1">
	<div class="flex lg:flex-row flex-col border-b border-coolgray-500 mb-6 space-x-2">
		<div class="flex flex-row">
			<div class="title font-bold pb-3 pr-3">Build Logs</div>
			<button class="btn btn-sm bg-error" on:click={resetQueue}>Reset Build Queue</button>
		</div>
		<div class=" flex-1" />
		<div class="form-control">
			<label class="label cursor-pointer">
				<span class="label-text text-white pr-4 font-bold">Enable Debug Logs</span>
				<input
					type="checkbox"
					checked={debug}
					class="checkbox checkbox-success"
					on:click={() => changeSettings('debug')}
				/>
			</label>
		</div>
	</div>
</div>
<div class="justify-start space-x-5 flex flex-col-reverse lg:flex-row">
	<div class="flex-1 md:w-96">
		{#if $selectedBuildId}
			{#key $selectedBuildId}
				<svelte:component this={BuildLog} />
			{/key}
		{:else if buildCount === 0}
			Not build logs found.
		{:else}
			Select a build to see the logs.
		{/if}
	</div>
	<div class="mb-4 min-w-[16rem] space-y-2 md:mb-0 ">
		<div class="top-4 md:sticky">
			<div class="flex space-x-2 pb-2">
				<button
					disabled={noMoreBuilds}
					class:btn-primary={!noMoreBuilds}
					class=" btn btn-sm w-full"
					on:click={loadMoreBuilds}>Load more</button
				>
			</div>
			{#each builds as build, index (build.id)}
				<!-- svelte-ignore a11y-click-events-have-key-events -->
				<div
					id={`building-${build.id}`}
					on:click={() => loadBuild(build.id)}
					class:rounded-tr={index === 0}
					class:rounded-br={index === builds.length - 1}
					class="flex cursor-pointer items-center justify-center py-4 no-underline transition-all duration-150 hover:bg-coolgray-300 hover:shadow-xl"
					class:bg-coolgray-200={$selectedBuildId === build.id}
				>
					<div class="flex-col px-2 text-center">
						<div class="text-sm font-bold truncate">
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

					<div class="w-32 text-center text-xs">
						{#if build.status === 'running'}
							<div>
								<span class="font-bold text-xl">{build.elapsed}s</span>
							</div>
						{:else if build.status !== 'queued'}
							<div>{day(build.updatedAt).utc().fromNow()}</div>
							<div>
								Finished in
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
	</div>
</div>
