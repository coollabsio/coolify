<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.service?.id) {
			return {
				props: {
					service: stuff.service,
					isRunning: stuff.isRunning,
					readOnly: stuff.readOnly
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
	import { getDomain } from '$lib/components/common';

	export let service;
	export let isRunning;
	export let readOnly;
</script>

<div class="flex items-center space-x-1 p-6 text-2xl font-bold">
	<div class="md:max-w-64 hidden truncate tracking-tight md:block">
		{service.name}
	</div>
	{#if service.fqdn}
		<span class="arrow-right-applications hidden px-1 md:block">></span>
		<a href={service.fqdn} target="_blank" class="pr-2">{getDomain(service.fqdn)}</a>
	{/if}
	<span class="arrow-right-applications hidden px-1 md:block">></span>
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

<Services bind:service {isRunning} {readOnly} />
