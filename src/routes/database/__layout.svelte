<script>
	import { browser } from '$app/env';

	import { goto } from '$app/navigation';
	import { page, session } from '$app/stores';
	import Tooltip from '$components/Tooltip.svelte';
	import { request } from '$lib/request';
	import { database, initialDatabase } from '$store';
	import { toast } from '@zerodevx/svelte-toast';
	import { onDestroy } from 'svelte';

	onDestroy(() => {
		$database = JSON.parse(JSON.stringify(initialDatabase));
	});

	async function removeDB() {
		await request(`/api/v1/databases/${$page.params.name}`, $session, {
			method: 'DELETE'
		});
		if (browser) {
			toast.push('Database removed.');
			goto(`/dashboard/databases`, { replaceState: true });
		}
	}
</script>

{#if $page.path !== '/database/new'}
	<nav class="flex text-white justify-end items-center m-4 fixed right-0 top-0 space-x-4">
		<Tooltip position="bottom" label="Delete">
			<button title="Delete" class="icon hover:text-red-500" on:click={removeDB}>
				<svg
					class="w-6"
					xmlns="http://www.w3.org/2000/svg"
					fill="none"
					viewBox="0 0 24 24"
					stroke="currentColor"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
					/>
				</svg>
			</button>
		</Tooltip>
		<div class="border border-warmGray-700 h-8" />
		<Tooltip position="bottom-left" label="Configuration">
			<button
				class="icon hover:text-yellow-400"
				disabled={$page.path === '/database/new'}
				class:text-yellow-400={$page.path.endsWith('configuration') ||
					$page.path === '/database/new'}
				class:bg-warmGray-700={$page.path.endsWith('configuration') ||
					$page.path === '/database/new'}
				on:click={() => goto(`/database/${$page.params.name}/configuration`)}
			>
				<svg
					class="w-6"
					xmlns="http://www.w3.org/2000/svg"
					fill="none"
					viewBox="0 0 24 24"
					stroke="currentColor"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
					/>
				</svg>
			</button>
		</Tooltip>
	</nav>
{/if}
<div class="text-white">
	<slot />
</div>
