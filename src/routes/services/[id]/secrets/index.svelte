<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/services/${params.id}/secrets.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					service: stuff.service,
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
	export let secrets;
	export let service;
	import Secret from './_Secret.svelte';
	import { getDomain } from '$lib/components/common';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import ServiceLinks from '$lib/components/ServiceLinks.svelte';

	const { id } = $page.params;

	async function refreshSecrets() {
		const data = await get(`/services/${id}/secrets.json`);
		secrets = [...data.secrets];
	}
</script>

<div
	class="flex items-center space-x-2 p-5 px-6 font-bold"
	class:p-5={service.fqdn}
	class:p-6={!service.fqdn}
>
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">Secrets</div>
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
	<table class="mx-auto border-separate text-left">
		<thead>
			<tr class="h-12">
				<th scope="col">Name</th>
				<th scope="col">Value</th>
				<th scope="col" class="w-96 text-center">Action</th>
			</tr>
		</thead>
		<tbody>
			{#each secrets as secret}
				{#key secret.id}
					<tr>
						<Secret name={secret.name} value={secret.value} on:refresh={refreshSecrets} />
					</tr>
				{/key}
			{/each}
			<tr>
				<Secret isNewSecret on:refresh={refreshSecrets} />
			</tr>
		</tbody>
	</table>
</div>
