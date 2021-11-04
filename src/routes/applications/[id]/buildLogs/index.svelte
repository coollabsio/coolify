<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		const buildId = page.query.get('buildId');
		let url = `/applications/${page.params.id}/buildLogs.json`;
		if (buildId) {
			url = `/applications/${page.params.id}/buildLogs.json?buildId=${buildId}`;
		}
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

<script>
	export let builds;
	export let application;
	export let logs;
	import { page } from '$app/stores';
	const { id } = $page.params;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Build logs of {application.domain}</div>
</div>
<div class="flex flex-row px-10 justify-start">
	<div class="min-w-[16rem] space-y-2">
		{#each builds as build (build.id)}
			<a
				sveltekit:prefetch
				href="/applications/{id}/buildLogs?buildId={build.id}"
				class="flex py-4 cursor-pointer transition-all duration-100 border-l-2 hover:shadow-xl no-underline hover:bg-coolgray-400 border-transparent"
				class:hover:border-red-500={build.status === 'failed'}
				class:hover:border-green-500={build.status === 'success'}
				class:hover:border-yellow-500={build.status === 'inprogress'}
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
			</a>
		{/each}
	</div>
	<div class="px-4 w-full">
		{#if logs.length > 0}
			<pre
				class=" w-full leading-4 text-left text-sm font-semibold tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap">
				{#each logs as log}
					{log.line + '\n'}
				{/each}
			</pre>
		{/if}
	</div>
</div>
