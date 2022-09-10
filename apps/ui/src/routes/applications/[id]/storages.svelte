<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/storages`);
			return {
				props: {
					application: stuff.application,
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
	export let application: any;
	export let persistentStorages: any;
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/applications/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	
	<div class="flex flex-col justify-center py-4 text-center">
		<h1 class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block font-bold mb-4">
			Persistent Storage
		</h1>
		<SimpleExplainer customClass="w-full" text={$t('application.storage.persistent_storage_explainer')} />
	</div>
	<table class="mx-auto border-separate text-left">
		<thead>
			<tr class="h-12">
				<th scope="col">{$t('forms.path')}</th>
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
