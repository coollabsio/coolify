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

	let search = '';
	let filteredTypes = types;

	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';

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
	function doSearch() {
		filteredTypes = types.filter(
			(type: any) =>
				type.name.toLowerCase().includes(search.toLowerCase()) ||
				type.labels.some((label: string) => label.toLowerCase().includes(search.toLowerCase()))
		);
	}
	function cleanupSearch() {
		search = '';
		filteredTypes = types;
	}
</script>

<div class="container lg:mx-auto lg:p-0 px-8 pt-5">
	<div class="input-group flex w-full">
		<div class="btn btn-square cursor-default no-animation hover:bg-error" on:click={cleanupSearch}>
			<svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentcolor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<line x1="18" y1="6" x2="6" y2="18" />
				<line x1="6" y1="6" x2="18" y2="18" />
			</svg>
		</div>
		<input
			id="search"
			class="input w-full"
			type="text"
			placeholder="Search for services"
			bind:value={search}
			on:input={() => doSearch()}
		/>
	</div>
</div>
<div class="container lg:mx-auto lg:pt-20 lg:p-0 px-8 pt-20">
<div class="flex flex-wrap justify-center  gap-8">
	{#each filteredTypes as type}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(type.name)}>
				<button type="submit" class="box-selection relative text-xl font-bold hover:bg-pink-600">
					<ServiceIcons type={type.name} />
					{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
</div>
