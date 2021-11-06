<script lang="ts">
	export let buildId;

	import { createEventDispatcher, onMount } from 'svelte';
	const dispatch = createEventDispatcher();

	import { page } from '$app/stores';
	import { asyncSleep } from './common';

	import Loading from './Loading.svelte';
	import LoadingLogs from './LoadingLogs.svelte';

	let logs = [];
	let loading = true;
	let currentStatus;
	const { id } = $page.params;

	async function streamLogs(sequence = 0) {
		const response = await fetch(
			`/applications/${id}/logs/build.json?buildId=${buildId}&sequence=${sequence}`
		);
		if (response.ok) {
			
			let { logs: responseLogs, status } = await response.json();
			currentStatus = status
			dispatch('updateBuildStatus', { status });
			logs = logs.concat(responseLogs);
			loading = false;
			while (status === 'running') {
				const nextSequence = logs[logs.length - 1].time;
				const res = await fetch(
					`/applications/${id}/logs/build.json?buildId=${buildId}&sequence=${nextSequence}`
				);
				if (res.ok) {
					const data = await res.json();
					status = data.status;
					currentStatus = status
					logs = logs.concat(data.logs);

					dispatch('updateBuildStatus', { status: data.status });
					await asyncSleep(1000);
				}
			}
		}
	}
	onMount(async () => {
		await streamLogs();
	});
</script>

{#if loading}
	<Loading />
{:else}
	<div class="w-full pl-4 relative">
		{#if currentStatus === 'running'}
		<LoadingLogs/>
		{/if}
		<pre
			class="w-full leading-4 text-left text-sm font-semibold tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap ">

        {#each logs as log}
            <div>{log.line + '\n'}</div>
        {/each}


    </pre>
	</div>
{/if}
