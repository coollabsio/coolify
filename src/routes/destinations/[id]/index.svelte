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
	import { t } from '$lib/translations';
</script>

<div class="flex h-20 items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
		</div>
		<span class="text-xs">{destination.name}</span>
	</div>
</div>

<div class="mx-auto max-w-4xl px-6">
	{#if destination.remoteEngine}
		<RemoteDocker bind:destination {settings} {state} />
	{:else}
		<LocalDocker bind:destination {settings} {state} />
	{/if}
</div>
