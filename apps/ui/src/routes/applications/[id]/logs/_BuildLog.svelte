<script lang="ts">
	export let buildId: any;

	import { createEventDispatcher, onDestroy, onMount } from 'svelte';
	const dispatch = createEventDispatcher();

	import { page } from '$app/stores';

	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import LoadingLogs from '$lib/components/LoadingLogs.svelte';
	import { errorNotification } from '$lib/common';
	import Tooltip from '$lib/components/Tooltip.svelte';

	let logs: any = [];
	let currentStatus: any;
	let streamInterval: any;
	let followingBuild: any;
	let followingInterval: any;
	let logsEl: any;

	let cancelInprogress = false;

	const { id } = $page.params;

	const cleanAnsiCodes = (str: string) => str.replace(/\x1B\[(\d+)m/g, '');

	function followBuild() {
		followingBuild = !followingBuild;
		if (followingBuild) {
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
			let { logs: responseLogs, status } = await get(
				`/applications/${id}/logs/build/${buildId}?sequence=${sequence}`
			);
			currentStatus = status;
			logs = logs.concat(
				responseLogs.map((log: any) => ({ ...log, line: cleanAnsiCodes(log.line) }))
			);
			streamInterval = setInterval(async () => {
				if (status !== 'running' && status !== 'queued') {
					clearInterval(streamInterval);
					return;
				}
				const nextSequence = logs[logs.length - 1]?.time || 0;
				try {
					const data = await get(
						`/applications/${id}/logs/build/${buildId}?sequence=${nextSequence}`
					);
					status = data.status;
					currentStatus = status;

					logs = logs.concat(
						data.logs.map((log: any) => ({ ...log, line: cleanAnsiCodes(log.line) }))
					);
					dispatch('updateBuildStatus', { status, took: data.took });
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
			await post(`/applications/${id}/cancel`, {
				buildId,
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

<div class="relative ">
	{#if currentStatus === 'running'}
		<LoadingLogs />
	{/if}
	{#if currentStatus === 'queued'}
		<div class="text-center font-bold text-xl">{$t('application.build.queued_waiting_exec')}</div>
	{:else}
		<div class="flex justify-end sticky top-0 p-2 mx-1">
			<button
				id="follow"
				on:click={followBuild}
				class="bg-transparent btn btn-sm btn-linkhover:text-green-500 hover:bg-coolgray-500"
				class:text-green-500={followingBuild}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
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
			</button>
			<Tooltip triggeredBy="#follow">Follow Logs</Tooltip>
			{#if currentStatus === 'running'}
				<button
					id="cancel"
					on:click={cancelBuild}
					class:animation-spin={cancelInprogress}
					class="bg-transparent btn btn-sm btn-link hover:text-red-500 hover:bg-coolgray-500"
				>
					{#if cancelInprogress}
						Cancelling...
					{:else}
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="w-6 h-6"
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
					{/if}
				</button>
				<Tooltip triggeredBy="#cancel">Cancel build</Tooltip>
			{/if}
		</div>
		{#if logs.length > 0}
			<div
				class="font-mono leading-6 text-left text-md tracking-tighter rounded bg-coolgray-200 py-5 px-6 whitespace-pre-wrap break-words overflow-auto max-h-[80vh] -mt-12 overflow-y-scroll scrollbar-w-1 scrollbar-thumb-coollabs scrollbar-track-coolgray-200"
				bind:this={logsEl}
			>
				{#each logs as log}
					<div>{log.line + '\n'}</div>
				{/each}
			</div>
		{:else}
			<div
				class="font-mono leading-6 text-left text-md tracking-tighter rounded bg-coolgray-200 py-5 px-6 whitespace-pre-wrap break-words overflow-auto max-h-[80vh] -mt-12 overflow-y-scroll scrollbar-w-1 scrollbar-thumb-coollabs scrollbar-track-coolgray-200"
			>
				No logs found.
			</div>
		{/if}
	{/if}
</div>
