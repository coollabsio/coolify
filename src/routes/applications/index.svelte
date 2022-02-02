<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const endpoint = '/applications.json';
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	export let applications: Array<Applications>;
	import { session } from '$app/stores';
	import Application from './_Application.svelte';
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl ">Applications</div>
	{#if $session.isAdmin}
		<a href="/new/application" class="add-icon bg-green-600 hover:bg-green-500">
			<svg
				class="w-6"
				xmlns="http://www.w3.org/2000/svg"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				><path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M12 6v6m0 0v6m0-6h6m-6 0H6"
				/></svg
			>
		</a>
	{/if}
</div>
<div class="flex flex-wrap justify-center">
	{#if !applications || applications.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No applications found</div>
		</div>
	{:else}
		{#each applications as application}
			<Application {application} />
		{/each}
	{/if}
</div>
