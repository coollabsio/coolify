<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const response = await get(`/settings`);
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
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let sshKeys: any;

	async function handleSubmit(sshKeyId: string) {
		try {
			await post(`/destinations/${id}/configuration/sshKey`, { id: sshKeyId });
			return await goto(from || `/destinations/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex flex-col justify-center">
	<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row ">
		{#if sshKeys.length > 0}
			{#each sshKeys as sshKey}
				<div class="p-2 relative">
					<form on:submit|preventDefault={() => handleSubmit(sshKey.id)}>
						<button
							type="submit"
							class="disabled:opacity-95 bg-coolgray-200 disabled:text-white box-selection hover:bg-orange-700 group"
						>
							<div class="font-bold text-xl text-center truncate">{sshKey.name}</div>
						</button>
					</form>
				</div>
			{/each}
		{:else}
			<div class="flex-col">
				<div class="pb-2 text-center font-bold">No SSH key found</div>
				<div class="flex justify-center">
					<a href="/settings/ssh" class="add-icon bg-sky-600 hover:bg-sky-500">
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
		{/if}
	</div>
</div>
