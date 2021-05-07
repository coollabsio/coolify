<script>
	import { onDestroy, onMount } from 'svelte';
	import { fade } from 'svelte/transition';
	import Loading from '$components/Loading.svelte';
	import { request } from '$lib/fetch';
	import { page, session } from '$app/stores';
	import { goto } from '$app/navigation';
import { browser } from '$app/env';

	let loadLogsInterval;
	let logs = [];

	onMount(() => {
		loadLogsInterval = setInterval(() => {
			loadLogs();
		}, 500);
	});

	async function loadLogs() {
		try {
			const { events, progress } = await request(
				`/api/v1/application/deploy/logs/${$page.params.deployId}`,
				$session
			);
			logs = [...events];
			if (progress === 'done' || progress === 'failed') {
				clearInterval(loadLogsInterval);
			}
		} catch (error) {
			browser && goto('/dashboard', { replaceState: true });
		}
	}
	onDestroy(() => {
		clearInterval(loadLogsInterval);
	});
</script>

<div
	class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
	in:fade={{ duration: 100 }}
>
	<div>Deployment log</div>
</div>
{#await loadLogs()}
	<Loading />
{:then}
	<div class="text-center px-6" in:fade={{ duration: 100 }}>
		<div in:fade={{ duration: 100 }}>
			<pre
				class="leading-4 text-left text-sm font-semibold tracking-tighter rounded-lg bg-black p-6 whitespace-pre-wrap">
      {#if logs.length > 0}
        {#each logs as log}
          {log + '\n'}
        {/each}
      {:else}
        It's starting soon.
      {/if}
    </pre>
		</div>
	</div>
{/await}
