<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		let url = `/applications/${page.params.id}/logs.json?skip=0`;
		const res = await fetch(url);
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
	import { dateOptions } from '$lib/components/common';
	
	import BuildLog from './_BuildLog.svelte';

	export let builds;
	export let application;
	export let buildCount;

	let buildId;
	$: buildId;

	let skip = 0;
	let noMoreBuilds = buildCount < 5 || buildCount <= skip;
	const { id } = $page.params;

	let preselectedBuildId = $page.query.get('buildId');
	if (preselectedBuildId) buildId = preselectedBuildId;

	async function updateBuildStatus({ detail }) {
		const { status } = detail;
		if (status !== 'running') {
			let url = `/applications/${id}/logs.json?buildId=${buildId}`;
			const res = await fetch(url);
			if (res.ok) {
				const data = await res.json();
				builds = builds.filter((build) => {
					if (build.id === data.builds[0].id) {
						build.status = data.builds[0].status;
						build.took = data.builds[0].took;
						build.since = data.builds[0].since;
					}
					return build;
				});
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
			let url = `/applications/${id}/logs.json?skip=${skip}`;
			const res = await fetch(url);
			if (res.ok) {
				const data = await res.json();
				builds = builds.concat(data.builds);
			}
		} else {
			noMoreBuilds = true;
		}
	}
</script>

<div class="font-bold flex space-x-1 py-6 px-6">
	<div class="text-2xl tracking-tight mr-4">Logs of <a href="http://{application.domain}" target="_blank">{application.domain}</a></div>
</div>
<div class="flex flex-row px-10 justify-start pt-6 space-x-2 ">
	<div class="min-w-[16rem] space-y-2">
		{#each builds as build (build.id)}
			<div
				data-tooltip={new Intl.DateTimeFormat('default', dateOptions).format(
					new Date(build.createdAt)
				) + `\n${build.status}`}
				on:click={() => (buildId = build.id)}
				class="flex py-4 cursor-pointer transition-all duration-100 border-l-2 hover:shadow-xl no-underline hover:bg-coolgray-400 border-transparent tooltip-top rounded-r"
				class:bg-coolgray-400={buildId === build.id}
				class:border-red-500={build.status === 'failed'}
				class:border-green-500={build.status === 'success'}
				class:border-yellow-500={build.status === 'inprogress'}
			>
				<div class="flex space-x-2 px-2">
					<div class="font-bold text-sm flex justify-center items-center">
						{application.branch}
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
		{#if buildCount > 0 && !noMoreBuilds}
			<button class="w-full"  on:click={loadMoreBuilds}
				>Load More</button
			>
		{/if}
	</div>
	<div class="flex-1">
		{#if buildId}
			{#key buildId}
				<svelte:component this={BuildLog} {buildId} on:updateBuildStatus={updateBuildStatus} />
			{/key}
		{/if}
	</div>
</div>
{#if buildCount === 0}
	<div class="text-center font-bold text-xl">No logs found</div>
{/if}
