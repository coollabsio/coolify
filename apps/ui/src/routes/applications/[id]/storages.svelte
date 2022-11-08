<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/storages`);
			return {
				props: {
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
	export let persistentStorages: any;
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/applications/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6  space-x-2">
			<div class="title font-bold pb-3">
				Persistent Volumes <Explainer
					position="dropdown-bottom"
					explanation={$t('application.storage.persistent_storage_explainer')}
				/>
			</div>
		</div>
		
		{#each persistentStorages as storage}
			{#key storage.id}
				<Storage on:refresh={refreshStorage} {storage} />
			{/key}
		{/each}
		<Storage on:refresh={refreshStorage} isNew />
	</div>
</div>
