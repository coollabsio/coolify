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

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Select a SSH Keys</div>
</div>
<div class="flex flex-col justify-center">
	<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row ">
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
	</div>
</div>
