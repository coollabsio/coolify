<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { onDestroy, onMount } from 'svelte';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		let endpoint = `/applications/${params.id}/logs.json`;
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
	export let application;
	import { page } from '$app/stores';
	import LoadingLogs from './_Loading.svelte';
	import { getDomain } from '$lib/components/common';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';

	let loadLogsInterval = null;
	let logs = [];
	let followingBuild;
	let followingInterval;
	let logsEl;

	const { id } = $page.params;
	onMount(async () => {
		loadLogs();
		loadLogsInterval = setInterval(() => {
			loadLogs();
		}, 1000);
	});
	onDestroy(() => {
		clearInterval(loadLogsInterval);
		clearInterval(followingInterval);
	});
	async function loadLogs() {
		try {
			const newLogs = await get(`/applications/${id}/logs.json`);
			logs = newLogs.logs;
			return;
		} catch ({ error }) {
			return errorNotification(error);
		}
	}

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
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Application logs of <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>
<div class="flex flex-row justify-center space-x-2 px-10 pt-6">
	{#if logs.length === 0}
		<div class="text-xl font-bold tracking-tighter">Waiting for the logs...</div>
	{:else}
		<div class="relative w-full">
			<LoadingLogs />
			<div class="flex justify-end sticky top-0 p-2">
				<button
					on:click={followBuild}
					data-tooltip="Follow logs"
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
			</div>
			<div
				class="font-mono leading-6 text-left text-md tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap break-words w-full mb-10 -mt-12 overflow-y-visible scrollbar-w-1 scrollbar-thumb-coollabs scrollbar-track-coolgray-200"
				bind:this={logsEl}
			>
				{#each logs as log}
					{log + '\n'}
				{/each}
			</div>
		</div>
	{/if}
</div>
