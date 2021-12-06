<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		if (stuff?.destination) {
			return {
				props: {
					destination: stuff.destination
				}
			};
		}
		const url = `/destinations/${page.params.id}.json`;
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
	import type Prisma from '@prisma/client';
	import LocalDocker from './_LocalDocker.svelte';
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl">
	<div class="tracking-tight">Destination</div>
	<span class="px-1 arrow-right-applications">></span>
	<span class="pr-2">{destination.name}</span>
</div>

<LocalDocker {destination} />
