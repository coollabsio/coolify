<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.service?.id) {
			return {
				props: {
					service: stuff.service,
					isRunning: stuff.isRunning,
					readOnly: stuff.readOnly,
					settings: stuff.settings
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
	import cuid from 'cuid';
	import { browser } from '$app/env';
	import ServiceLinks from '$lib/components/ServiceLinks.svelte';
	import Services from './_Services/_Services.svelte';

	export let service;
	export let isRunning;
	export let readOnly;
	export let settings;

	if (browser && window.location.hostname === 'demo.coolify.io' && !service.fqdn) {
		service.fqdn = `http://${cuid()}.demo.coolify.io`;
	}
</script>

<div class="flex h-20 items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
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

<Services bind:service {isRunning} {readOnly} {settings} />
