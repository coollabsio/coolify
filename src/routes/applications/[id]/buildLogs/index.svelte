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
	const buildId = $page.query.get('buildId');

	async function queryLogs(build) {
		logs = [];
		const res = await fetch(`/applications/${id}/buildLogs.json?buildId=${build.id}`);
		if (res.ok) {
			const data = await res.json();
			builds = data.builds;
			logs = logs.concat(data.logs);
		}
		let interval;
		if (build.status === 'running') {
			interval = setInterval(async () => {
				const last = logs[logs.length - 1].time;
				const res = await fetch(
					`/applications/${id}/buildLogs.json?buildId=${build.id}&last=${last}`
				);
				if (res.ok) {
					const data = await res.json();
					logs = logs.concat(data.logs);
					builds = data.builds;
					if (data.status !== 'running') clearInterval(interval);
				}
			}, 1000);
		}
	}

	// if (buildId) {
	// 	const interval = setInterval(async () => {
	// 		const lastTime = logs[logs.length - 1];
	// 		console.log(builds);
	// 		const res = await fetch(`/applications/${id}/buildLogs.json?buildId=${buildId}`);
	// 		if (res.ok) {
	// 			const data = await res.json();
	// 			if (data.status === 'running') {
	// 				logs = data.logs;
	// 			} else {
	// 				clearInterval(interval);
	// 			}
	// 		}
	// 	}, 1000);
	// }
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Build logs of {application.domain}</div>
</div>
<div class="flex flex-row px-10 justify-start">
	<div class="min-w-[16rem] space-y-2">
		{#each builds as build (build.id)}
			<div
				on:click={() => queryLogs(build)}
				class="flex py-4 cursor-pointer transition-all duration-100 border-l-2 hover:shadow-xl no-underline hover:bg-coolgray-400 border-transparent"
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
	{#if logs.length > 0}
		<div class="px-4 w-full">
			<pre
				class=" w-full leading-4 text-left text-sm font-semibold tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap">
				{#each logs as log}
					{log.line + '\n'}
				{/each}
			</pre>
		</div>
	{/if}
</div>
