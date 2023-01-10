<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { database } = stuff;
			if (database?.destinationDockerId && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/database/${params.id}`
				};
			}
			const response = await get(`/destinations?onlyVerified=true`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { onMount } from 'svelte';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let destinations: any;
	async function handleSubmit(destinationId: any) {
		try {
			await post(`/databases/${id}/configuration/destination`, {
				destinationId
			});
			return await goto(from || `/databases/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
	onMount(async () => {
		if (destinations.length === 1) {
			await handleSubmit(destinations[0].id);
		}
	});
</script>

<div class="flex justify-center">
	{#if !destinations || destinations.length === 0}
		<div class="flex-col">
			<div class="pb-2 text-center font-bold">
				{$t('application.configuration.no_configurable_destination')}
			</div>
			<div class="flex justify-center">
				<a href="/destinations/new" class="add-icon bg-sky-600 hover:bg-sky-500">
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
			</div>
		</div>
	{:else}
		<div class="flex flex-wrap justify-center">
			{#each destinations as destination}
				<div class="p-2">
					<form on:submit|preventDefault={() => handleSubmit(destination.id)}>
						<button type="submit" class="box-selection hover:bg-sky-700 font-bold">
							<div class="font-bold text-xl text-center truncate">{destination.name}</div>
							<div class="text-center truncate">{destination.network}</div>
						</button>
					</form>
				</div>
			{/each}
		</div>
	{/if}
</div>
