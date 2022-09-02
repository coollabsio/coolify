<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const response = await get(`/services/${params.id}/storages`);
			return {
				props: {
					service: stuff.service,
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
	export let service: any;
	export let persistentStorages: any;
    
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import ServiceLinks from './_ServiceLinks.svelte';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/services/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div
	class="flex items-center space-x-2 p-5 px-6 font-bold"
	class:p-5={service.fqdn}
	class:p-6={!service.fqdn}
>
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Persistent Storage
		</div>
		<span class="text-xs">{service.name}</span>
	</div>
	{#if service.fqdn}
		<a
			href={service.fqdn}
			target="_blank"
			class="icons tooltip-bottom flex items-center bg-transparent text-sm"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="h-6 w-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
				<line x1="10" y1="14" x2="20" y2="4" />
				<polyline points="15 4 20 4 20 9" />
			</svg></a
		>
	{/if}

	<ServiceLinks {service} />
</div>

<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<div class="flex justify-center py-4 text-center">
		<SimpleExplainer
			customClass="w-full"
			text={'You can specify any folder that you want to be persistent across restarts. <br>This is useful for storing data for VSCode server or WordPress.'}
		/>
	</div>
	<table class="mx-auto border-separate text-left">
		<thead>
			<tr class="h-12">
				<th scope="col">Path</th>
			</tr>
		</thead>
		<tbody>
			{#each persistentStorages as storage}
				{#key storage.id}
					<tr>
						<Storage on:refresh={refreshStorage} {storage} />
					</tr>
				{/key}
			{/each}
			<tr>
				<Storage on:refresh={refreshStorage} isNew />
			</tr>
		</tbody>
	</table>
</div>
