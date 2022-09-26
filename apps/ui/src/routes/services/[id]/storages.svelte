<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const response = await get(`/services/${params.id}/storages`);
			return {
				props: {
					service: stuff.service,
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let service: any;
	export let persistentStorages: any;
    
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import ServiceLinks from './_ServiceLinks.svelte';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/services/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<div class="flex justify-center py-4 text-center">
		<SimpleExplainer
			customClass="w-full"
			text={'You can specify any folder that you want to be persistent across restarts. <br>This is useful for storing data for VSCode server or WordPress.'}
		/>
	</div>
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
						<Storage on:refresh={refreshStorage} {storage} />
					</tr>
				{/key}
			{/each}
			<tr>
				<Storage on:refresh={refreshStorage} isNew />
			</tr>
		</tbody>
	</table>
</div>
