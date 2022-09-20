<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { database } = stuff;
			if (database?.type && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/database/${params.id}`
				};
			}
			const response = await get(`/databases/${params.id}/configuration/type`);
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
	export let types: any;

	import { page } from '$app/stores';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
  
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import DatabaseIcons from '$lib/components/svg/databases/DatabaseIcons.svelte';
	async function handleSubmit(type: any) {
		try {
			await post(`/databases/${id}/configuration/type`, { type });
			return await goto(from || `/databases/${id}/configuration/version`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex flex-wrap justify-center">
	{#each types as type}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(type.name)}>
				<button type="submit" class="box-selection relative text-xl font-bold hover:bg-purple-700">
          <DatabaseIcons type={type.name} isAbsolute={true} />
					{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
