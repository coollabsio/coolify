<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/applications/${params.id}/storage.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					application: stuff.application,
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
	export let application;

	export let persistentStorages;
	import { getDomain } from '$lib/components/common';
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';

	const { id } = $page.params;
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Persistent storage for <a href={application.fqdn} target="_blank"
			>{getDomain(application.fqdn)}</a
		>
	</div>
</div>

<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
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
						<Storage {storage} />
					</tr>
				{/key}
			{/each}
			<tr>
				<Storage isNew />
			</tr>
		</tbody>
	</table>
</div>
