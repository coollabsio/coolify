<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/common';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import { day } from '$lib/dayjs';
	import { selectedBuildId, trpc } from '$lib/store';
	import { dev } from '$app/environment';

	let logs: any = [];
	let currentStatus: any;
	let streamInterval: any;
	let followingLogs: any;
	let followingInterval: any;
	let logsEl: any;
	let fromDb = false;
	let cancelInprogress = false;
	let position = 0;
	let loading = true;
	const { id } = $page.params;

	const cleanAnsiCodes = (str: string) => str.replace(/\x1B\[(\d+)m/g, '');

	function detect() {
		if (position < logsEl.scrollTop) {
			position = logsEl.scrollTop;
		} else {
			if (followingLogs) {
				clearInterval(followingInterval);
				followingLogs = false;
			}
			position = logsEl.scrollTop;
		}
	}
	function followBuild() {
		followingLogs = !followingLogs;
		if (followingLogs) {
			followingInterval = setInterval(() => {
				logsEl.scrollTop = logsEl.scrollHeight;
				window.scrollTo(0, document.body.scrollHeight);
			}, 100);
		} else {
			window.clearInterval(followingInterval);
		}
	}
	async function streamLogs(sequence = 0) {
		try {
			loading = true;
			let {
				logs: responseLogs,
				status,
				fromDb: from
			} = await trpc.applications.getBuildLogs.query({ id, buildId: $selectedBuildId, sequence });

			currentStatus = status;
			logs = logs.concat(
				responseLogs.map((log: any) => ({ ...log, line: cleanAnsiCodes(log.line) }))
			);
			fromDb = from;

			streamInterval = setInterval(async () => {
				const nextSequence = logs[logs.length - 1]?.time || 0;
				if (status !== 'running' && status !== 'queued') {
					loading = false;
					try {
						const data = await trpc.applications.getBuildLogs.query({
							id,
							buildId: $selectedBuildId,
							sequence: nextSequence
						});
						status = data.status;
						currentStatus = status;
						fromDb = data.fromDb;

						logs = logs.concat(
							data.logs.map((log: any) => ({ ...log, line: cleanAnsiCodes(log.line) }))
						);
						loading = false;
					} catch (error) {
						return errorNotification(error);
					}
					clearInterval(streamInterval);
					return;
				}
				try {
					const data = await trpc.applications.getBuildLogs.query({
						id,
						buildId: $selectedBuildId,
						sequence: nextSequence
					});
					status = data.status;
					currentStatus = status;
					fromDb = data.fromDb;

					logs = logs.concat(
						data.logs.map((log: any) => ({ ...log, line: cleanAnsiCodes(log.line) }))
					);
					loading = false;
				} catch (error) {
					return errorNotification(error);
				}
			}, 1000);
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function cancelBuild() {
		if (cancelInprogress) return;
		try {
			cancelInprogress = true;
			await trpc.applications.cancelBuild.mutate({
				buildId: $selectedBuildId,
				applicationId: id
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	onDestroy(() => {
		clearInterval(streamInterval);
		clearInterval(followingInterval);
	});
	onMount(async () => {
		window.scrollTo(0, 0);
		await streamLogs();
	});
</script>

<div class="flex justify-start top-0 pb-2 space-x-2">
	<button
		on:click={followBuild}
		class="btn btn-sm bg-coollabs"
		disabled={currentStatus !== 'running'}
		class:bg-coolgray-300={followingLogs || currentStatus !== 'running'}
		class:text-applications={followingLogs}
	>
		<svg
			xmlns="http://www.w3.org/2000/svg"
			class="w-6 h-6 mr-2"
			viewBox="0 0 24 24"
			stroke-width="1.5"
			stroke="currentColor"
			fill="none"
			stroke-linecap="round"
			stroke-linejoin="round"
		>
			<path stroke="none" d="M0 0h24v24H0z" fill="none" />
			<circle cx="12" cy="12" r="9" />
			<line x1="8" y1="12" x2="12" y2="16" />
			<line x1="12" y1="8" x2="12" y2="16" />
			<line x1="16" y1="12" x2="12" y2="16" />
		</svg>

		{followingLogs ? 'Following Logs...' : 'Follow Logs'}
	</button>

	<button
		on:click={cancelBuild}
		class:animation-spin={cancelInprogress}
		class="btn btn-sm"
		disabled={currentStatus !== 'running'}
		class:bg-coolgray-300={cancelInprogress || currentStatus !== 'running'}
	>
		<svg
			xmlns="http://www.w3.org/2000/svg"
			class="w-6 h-6 mr-2"
			viewBox="0 0 24 24"
			stroke-width="1.5"
			stroke="currentColor"
			fill="none"
			stroke-linecap="round"
			stroke-linejoin="round"
		>
			<path stroke="none" d="M0 0h24v24H0z" fill="none" />
			<circle cx="12" cy="12" r="9" />
			<path d="M10 10l4 4m0 -4l-4 4" />
		</svg>
		{cancelInprogress ? 'Cancelling...' : 'Cancel Build'}
	</button>
	{#if currentStatus === 'running'}
		<button id="streaming" class="btn btn-sm bg-transparent border-none loading" />
		<Tooltip triggeredBy="#streaming">Streaming logs</Tooltip>
	{/if}
</div>
{#if currentStatus === 'queued'}
	<div
		class="font-mono w-full bg-coolgray-200 p-5 overflow-x-auto overflox-y-auto max-h-[80vh] rounded mb-20 flex flex-col whitespace-nowrap scrollbar-thumb-coollabs scrollbar-track-coolgray-200 scrollbar-w-1"
	>
		Queued and waiting for execution.
	</div>
{:else if logs.length > 0}
	<div
		bind:this={logsEl}
		on:scroll={detect}
		class="font-mono w-full bg-coolgray-100 border border-coolgray-200 p-5 overflow-x-auto overflox-y-auto max-h-[80vh] rounded mb-20 flex flex-col scrollbar-thumb-coollabs scrollbar-track-coolgray-200 scrollbar-w-1 whitespace-pre"
	>
		{#each logs as log}
			{#if fromDb}
				{log.line + '\n'}
			{:else}
				[{day.unix(log.time).format('HH:mm:ss.SSS')}] {log.line + '\n'}
			{/if}
		{/each}
	</div>
{:else}
	<div
		class="font-mono w-full bg-coolgray-200 p-5 overflow-x-auto overflox-y-auto max-h-[80vh] rounded mb-20 flex flex-col whitespace-nowrap scrollbar-thumb-coollabs scrollbar-track-coolgray-200 scrollbar-w-1"
	>
		{loading
			? 'Loading logs...'
			: dev
			? 'In development, logs are shown in the console.'
			: 'No logs found yet.'}
	</div>
{/if}
