<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		let endpoint = `/applications/${params.id}/logs/build.json?skip=0`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					application: stuff.application,
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
	import { page } from '$app/stores';
	import { dateOptions, getDomain } from '$lib/components/common';

	import BuildLog from './_BuildLog.svelte';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';

	export let builds;
	export let application;
	export let buildCount;

	let buildId;

	let skip = 0;
	let noMoreBuilds = buildCount < 5 || buildCount <= skip;
	const { id } = $page.params;
	let preselectedBuildId = $page.url.searchParams.get('buildId');
	if (preselectedBuildId) buildId = preselectedBuildId;

	async function updateBuildStatus({ detail }) {
		const { status } = detail;
		if (status !== 'running') {
			try {
				const data = await get(`/applications/${id}/logs/build.json?buildId=${buildId}`);
				builds = builds.filter((build) => {
					if (build.id === data.builds[0].id) {
						build.status = data.builds[0].status;
						build.took = data.builds[0].took;
						build.since = data.builds[0].since;
					}
					window.location.reload();
					return build;
				});
				return;
			} catch ({ error }) {
				return errorNotification(error);
			}
		} else {
			builds = builds.filter((build) => {
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
				const data = await get(`/applications/${id}/logs/build.json?skip=${skip}`);
				builds = builds.concat(data.builds);
				return;
			} catch ({ error }) {
				return errorNotification(error);
			}
		} else {
			noMoreBuilds = true;
		}
	}
	async function loadBuild(build) {
		buildId = build;
		goto(`/applications/${id}/logs/build?buildId=${buildId}`);
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Build logs of <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>
<div class="block flex-row justify-start space-x-2 px-5 pt-6 sm:px-10 md:flex">
	<div class="mb-4 min-w-[16rem] space-y-2 md:mb-0 ">
		<div class="top-4 md:sticky">
			{#each builds as build (build.id)}
				<div
					data-tooltip={new Intl.DateTimeFormat('default', dateOptions).format(
						new Date(build.createdAt)
					) + `\n${build.status}`}
					on:click={() => loadBuild(build.id)}
					class="tooltip-top flex cursor-pointer items-center justify-center rounded-r border-l-2 border-transparent py-4 no-underline transition-all duration-100 hover:bg-coolgray-400 hover:shadow-xl "
					class:bg-coolgray-400={buildId === build.id}
					class:border-red-500={build.status === 'failed'}
					class:border-green-500={build.status === 'success'}
					class:border-yellow-500={build.status === 'inprogress'}
				>
					<div class="flex-col px-2">
						<div class="text-sm font-bold">
							{application.branch}
						</div>
						<div class="text-xs">
							{build.type}
						</div>
					</div>
					<div class="flex-1" />

					<div class="w-48 text-center text-xs">
						{#if build.status === 'running'}
							<div class="font-bold">Running</div>
						{:else}
							<div>{build.since}</div>
							<div>Finished in <span class="font-bold">{build.took}s</span></div>
						{/if}
					</div>
				</div>
			{/each}
		</div>
		<div class="flex space-x-2">
			<button disabled={buildCount > 0 && !noMoreBuilds} class="w-full" on:click={loadMoreBuilds}
				>Load More</button
			>
		</div>
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
	<div class="text-center text-xl font-bold">No logs found</div>
{/if}
