<script lang="ts">
	export let buildId;

	import { createEventDispatcher, onDestroy, onMount } from 'svelte';
	const dispatch = createEventDispatcher();

	import { page } from '$app/stores';

	import Loading from '$lib/components/Loading.svelte';
	import LoadingLogs from '../_Loading.svelte';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';

	let logs = [];
	let loading = true;
	let currentStatus;
	let streamInterval;

	const { id } = $page.params;

	async function streamLogs(sequence = 0) {
		try {
			let { logs: responseLogs, status } = await get(
				`/applications/${id}/logs/build/build.json?buildId=${buildId}&sequence=${sequence}`
			);
			currentStatus = status;
			logs = logs.concat(responseLogs);
			loading = false;
			streamInterval = setInterval(async () => {
				if (status !== 'running') {
					clearInterval(streamInterval);
					return;
				}
				const nextSequence = logs[logs.length - 1].time;
				try {
					const data = await get(
						`/applications/${id}/logs/build/build.json?buildId=${buildId}&sequence=${nextSequence}`
					);
					status = data.status;
					currentStatus = status;
					logs = logs.concat(data.logs);
					dispatch('updateBuildStatus', { status });
				} catch ({ error }) {
					return errorNotification(error);
				}
			}, 1000);
		} catch ({ error }) {
			return errorNotification(error);
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
			class="leading-6 text-left text-md tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap break-words">
			{#each logs as log}
				<div>{log.line + '\n'}</div>
			{/each}
    	</pre>
	</div>
{/if}
