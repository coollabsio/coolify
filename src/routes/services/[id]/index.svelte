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

<div
	class="flex items-center space-x-3 px-6 text-2xl font-bold"
	class:p-5={service.fqdn}
	class:p-6={!service.fqdn}
>
	<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
		{service.name}
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

	<div>
		{#if service.type === 'plausibleanalytics'}
			<a href="https://plausible.io" target="_blank">
				<PlausibleAnalytics />
			</a>
		{:else if service.type === 'nocodb'}
			<a href="https://nocodb.com" target="_blank">
				<NocoDb />
			</a>
		{:else if service.type === 'minio'}
			<a href="https://min.io" target="_blank">
				<MinIo />
			</a>
		{:else if service.type === 'vscodeserver'}
			<a href="https://coder.com" target="_blank">
				<VsCodeServer />
			</a>
		{:else if service.type === 'wordpress'}
			<a href="https://wordpress.org" target="_blank">
				<Wordpress />
			</a>
		{/if}
	</div>
</div>

<Services bind:service {isRunning} {readOnly} />
