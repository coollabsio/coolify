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
	export let services: any;

	let search = '';
	let filteredServices = services;

	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';
	import { onMount } from 'svelte';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
	let searchInput: HTMLInputElement;

	onMount(() => {
		setTimeout(() => {
			searchInput.focus();
		}, 100);
	});
	async function handleSubmit(service: any) {
		try {
			await post(`/services/${id}/configuration/type`, { type: service.type });
			return await goto(from || `/services/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
	function doSearch() {
		filteredServices = services.filter(
			(service: any) =>
				service.name.toLowerCase().includes(search.toLowerCase()) ||
				service.labels?.some((label: string) =>
					label.toLowerCase().includes(search.toLowerCase())
				) ||
				service.description.toLowerCase().includes(search.toLowerCase())
		);
	}
	function cleanupSearch() {
		search = '';
		filteredServices = services;
	}
	function sortMe(data: any) {
		return data.sort((a, b) => {
			let fa = a.name.toLowerCase(),
				fb = b.name.toLowerCase();

			if (fa < fb) {
				return -1;
			}
			if (fa > fb) {
				return 1;
			}
			return 0;
		});
	}
</script>

<div class="container lg:mx-auto lg:p-0 px-8 pt-5">
	<div class="input-group flex w-full">
		<!-- svelte-ignore a11y-click-events-have-key-events -->
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
			bind:this={searchInput}
			id="search"
			class="input w-full input-primary"
			type="text"
			placeholder="Search for services"
			bind:value={search}
			on:input={() => doSearch()}
		/>
	</div>
</div>
<div class=" lg:pt-20 lg:p-0 px-8 pt-20">
	<div class="grid grid-flow-rows grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
		{#each sortMe(filteredServices).filter(s=> !s.ignore) as service}
			{#key service.name}
				<button
					on:click={() => handleSubmit(service)}
					class="box-selection relative text-xl font-bold hover:bg-primary"
				>
					<div class="flex flex-col">
						<div class="flex justify-center items-center gap-2 pb-4">
							<ServiceIcons type={service.type} />
							<div>{service.name}</div>
							{#if service.subname}
								<div class="text-sm">{service.subname}</div>
							{/if}
						</div>

						{#if service.description}
							<div class="text-sm font-mono">{service.description}</div>
						{/if}
					</div>
				</button>
			{/key}
		{/each}
	</div>
</div>
