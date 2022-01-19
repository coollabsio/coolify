<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.service?.id) {
			return {
				props: {
					service: stuff.service,
					isRunning: stuff.isRunning
				}
			};
		}
		const endpoint = `/services/${params.id}.json`;
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
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	import PlausibleAnalytics from '$lib/components/svg/services/PlausibleAnalytics.svelte';
	import NocoDb from '$lib/components/svg/services/NocoDB.svelte';
	import MinIo from '$lib/components/svg/services/MinIO.svelte';
	import VsCodeServer from '$lib/components/svg/services/VSCodeServer.svelte';
	import Wordpress from '$lib/components/svg/services/Wordpress.svelte';
	import Services from './_Services/_Services.svelte';

	export let service;
	export let isRunning;
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl items-center">
	<div class="tracking-tight truncate md:max-w-64 md:block hidden">
		{service.name}
	</div>
	{#if service.fqdn}
		<span class="px-1 arrow-right-applications md:block hidden">></span>
		<a href={service.fqdn} target="_blank" class="pr-2">{service.fqdn}</a>
	{/if}
	<span class="px-1 arrow-right-applications md:block hidden">></span>
	{#if service.type === 'plausibleanalytics'}
		<PlausibleAnalytics />
	{:else if service.type === 'nocodb'}
		<NocoDb />
	{:else if service.type === 'minio'}
		<MinIo />
	{:else if service.type === 'vscodeserver'}
		<VsCodeServer />
	{:else if service.type === 'wordpress'}
		<Wordpress />
	{/if}
</div>

<Services {service} {isRunning} />
