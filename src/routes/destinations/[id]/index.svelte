<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.destination.id) {
			return {
				props: {
					destination: stuff.destination,
					state: stuff.state,
					settings: stuff.settings
				}
			};
		}
		const url = `/destinations/${params.id}.json`;
		const res = await fetch(url);

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
	export let destination: Prisma.DestinationDocker;
	export let settings;
	export let state;

	import type Prisma from '@prisma/client';
	import LocalDocker from './_LocalDocker.svelte';
	import RemoteDocker from './_RemoteDocker.svelte';
</script>

<div class="flex space-x-1 p-6 text-2xl font-bold">
	<div class="tracking-tight">Destination</div>
	<span class="arrow-right-applications px-1">></span>
	<span class="pr-2">{destination.name}</span>
</div>

<div class="mx-auto max-w-4xl px-6">
	{#if destination.remoteEngine}
		<RemoteDocker bind:destination {settings} {state} />
	{:else}
		<LocalDocker bind:destination {settings} {state} />
	{/if}
</div>
