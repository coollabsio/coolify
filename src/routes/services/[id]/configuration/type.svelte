<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { service } = stuff;
		if (service?.type && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/services/${params.id}`
			};
		}
		const endpoint = `/services/${params.id}/configuration/type.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import PlausibleAnalytics from '$lib/components/svg/services/PlausibleAnalytics.svelte';
	import NocoDb from '$lib/components/svg/services/NocoDB.svelte';
	import MinIo from '$lib/components/svg/services/MinIO.svelte';
	import VsCodeServer from '$lib/components/svg/services/VSCodeServer.svelte';
	import Wordpress from '$lib/components/svg/services/Wordpress.svelte';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let types;

	async function handleSubmit(type) {
		try {
			await post(`/services/${id}/configuration/type.json`, { type });
			return await goto(from || `/services/${id}`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Service</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each types as type}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(type.name)}>
				<button type="submit" class="box-selection text-xl font-bold hover:bg-pink-600 relative">
					{#if type.name === 'plausibleanalytics'}
						<PlausibleAnalytics />
					{:else if type.name === 'nocodb'}
						<NocoDb />
					{:else if type.name === 'minio'}
						<MinIo />
					{:else if type.name === 'vscodeserver'}
						<VsCodeServer />
					{:else if type.name === 'wordpress'}
						<Wordpress />
					{/if}{type.fancyName}
				</button>
			</form>
		</div>
	{/each}
</div>
