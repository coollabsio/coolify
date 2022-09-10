<script context="module" lang="ts">
	import { get } from '$lib/api';
	import Usage from '$lib/components/Usage.svelte';

	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({}) => {
		try {
			const { servers } = await get('/servers');
			return {
				props: {
					servers
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let servers: any;
	import { appSession } from '$lib/store';
	import { goto } from '$app/navigation';
	if ($appSession.teamId !== '0') {
		goto('/');
	}
</script>

<div class="header p-5">
	<h1 class="text-2xl font-bold">Servers</h1>
</div>
<div class="container lg:mx-auto lg:p-0 px-8 p-5 lg:pt-20">
	{#if servers.length > 0}
		<div class="grid grid-col gap-8 auto-cols-max grid-cols-1  p-4">
			{#each servers as server}
				<div class="no-underline mb-5">
					<div class="w-full rounded bg-coolgray-100 indicator">
						{#if $appSession.teamId === '0'}
							<Usage {server} />
						{/if}
					</div>
				</div>
			{/each}
		</div>
	{:else}
		<h1 class="text-center text-xs">Nothing here.</h1>
	{/if}
</div>
<div class="text-xs text-center">Remote servers will be here soon</div>
