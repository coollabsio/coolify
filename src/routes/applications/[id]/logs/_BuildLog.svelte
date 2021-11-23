<script lang="ts">
	export let buildId;

	import { createEventDispatcher, onDestroy, onMount } from 'svelte';
	const dispatch = createEventDispatcher();

	import { page } from '$app/stores';

	import Loading from '$lib/components/Loading.svelte';
	import LoadingLogs from './_Loading.svelte';

	let logs = [];
	let loading = true;
	let currentStatus;
	const { id } = $page.params;
	let streamInterval;
	async function streamLogs(sequence = 0) {
		const response = await fetch(
			`/applications/${id}/logs/build.json?buildId=${buildId}&sequence=${sequence}`
		);
		if (response.ok) {
			let { logs: responseLogs, status } = await response.json();
			currentStatus = status;
			logs = logs.concat(responseLogs);
			loading = false;
			streamInterval = setInterval(async () => {
				if (status !== 'running') {
					clearInterval(streamInterval);
					return;
				}
				const nextSequence = logs[logs.length - 1].time;
				const res = await fetch(
					`/applications/${id}/logs/build.json?buildId=${buildId}&sequence=${nextSequence}`
				);
				if (res.ok) {
					const data = await res.json();
					status = data.status;
					currentStatus = status;
					logs = logs.concat(data.logs);
					dispatch('updateBuildStatus', { status });
				}
			}, 1000);
		}
	}
	onDestroy(() => {
		clearInterval(streamInterval);
	});
	onMount(async () => {
		window.scrollTo(0, 0);
		await streamLogs();
	});
</script>

{#if loading}
	<Loading />
{:else}
	<div class="relative">
		{#if currentStatus === 'running'}
			<LoadingLogs />
		{/if}
		<pre
			class="w-full leading-4 text-left text-sm font-semibold tracking-tighter rounded bg-black p-6 whitespace-pre-wrap">
			{#each logs as log}
				<div>{log.line + '\n'}</div>
			{/each}
    	</pre>
	</div>
{/if}
