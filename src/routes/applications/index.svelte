<script lang="ts">
	export let applications: Array<Application>;
	import { session } from '$app/stores';
	import Application from './_Application.svelte';
	import { post } from '$lib/api';
	import { goto } from '$app/navigation';
	async function newApplication() {
		const { id } = await post('/applications/new', {});
		return await goto(`/applications/${id}`, { replaceState: true });
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl ">Applications</div>
	{#if $session.isAdmin}
		<div on:click={newApplication} class="add-icon cursor-pointer bg-green-600 hover:bg-green-500">
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
		</div>
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
