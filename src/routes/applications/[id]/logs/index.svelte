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

	const { id } = $page.params;

	onMount(async () => {
		loadLogs();
		loadLogsInterval = setInterval(() => {
			loadLogs();
		}, 3000);
	});
	onDestroy(() => {
		clearInterval(loadLogsInterval);
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
			<pre
				class="leading-6 text-left text-md tracking-tighter rounded bg-coolgray-200 p-6 whitespace-pre-wrap break-words w-full">
				{#each logs as log}
					{log + '\n'}
				{/each}
  			</pre>
		</div>
	{/if}
</div>
