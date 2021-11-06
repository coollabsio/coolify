<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		let url = `/applications/${page.params.id}/logs.json`;
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
	import BuildLog from '$lib/components/BuildLog.svelte';
	import { page } from '$app/stores';

	export let builds;
	export let application;

    let buildId;
	$: buildId;

	let preselectedBuildId = $page.query.get('buildId');
    if (preselectedBuildId) buildId = preselectedBuildId

	function updateBuildStatus({ detail }) {
		const { status } = detail;
		builds = builds.filter((build) => {
			if (build.id === buildId) build.status = status;
			return build;
		});
	}
</script>


<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Build logs of {application.domain}</div>
</div>
<div class="flex flex-row px-10 justify-start pt-6">
	<div class="min-w-[16rem] space-y-2">
		{#each builds as build (build.id)}
			<div
				on:click={() => (buildId = build.id)}
				class="flex py-4 cursor-pointer transition-all duration-100 border-l-2 hover:shadow-xl no-underline hover:bg-coolgray-400 border-transparent"
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
				<div class="px-3 w-48">
					<div class="font-bold">{build.status}</div>
					<div class="text-xs">{build.createdAt}</div>
				</div>
			</div>
		{/each}
	</div>

	{#if buildId}
		{#key buildId}
			<svelte:component this={BuildLog} {buildId} on:updateBuildStatus={updateBuildStatus} />
		{/key}
	{/if}
</div>
