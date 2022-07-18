<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { service } = stuff;
			if (service?.type && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/services/${params.id}`
				};
			}
			const response = await get(`/services/${params.id}/configuration/type`);
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
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import Services from '../_Services.svelte';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	async function handleSubmit(type: any) {
		try {
			await post(`/services/${id}/configuration/type`, { type });
			return await goto(from || `/services/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('forms.select_a_service')}</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each types as type}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(type.name)}>
				<button type="submit" class="box-selection relative text-xl font-bold hover:bg-pink-600">
					<Services type={type.name} />
					{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
