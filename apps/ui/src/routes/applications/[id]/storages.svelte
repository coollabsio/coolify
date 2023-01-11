<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const { application } = stuff;
			const response = await get(`/applications/${params.id}/storages`);
			return {
				props: {
					application,
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
	export let application: any;
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';
	import { appSession } from '$lib/store';

	let composeJson = JSON.parse(application?.dockerComposeFile || '{}');
	let predefinedVolumes: any[] = [];
	if (composeJson?.services) {
		for (const [_, service] of Object.entries(composeJson.services)) {
			if (service?.volumes) {
				for (const [_, volumeName] of Object.entries(service.volumes)) {
					let [volume, target] = volumeName.split(':');
					if (volume === '.') {
						volume = target;
					}
					if (!target) {
						target = volume;
						volume = `${application.id}${volume.replace(/\//gi, '-').replace(/\./gi, '')}`;
					} else {
						volume = `${application.id}${volume.replace(/\//gi, '-').replace(/\./gi, '')}`;
					}
					predefinedVolumes.push({ id: volume, path: target, predefined: true });
				}
			}
		}
	}
	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/applications/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
			<div class="title font-bold pb-3">Persistent Volumes</div>
		</div>
		{#if predefinedVolumes.length > 0}
			<div class="title">Predefined Volumes</div>
			<div class="w-full lg:px-0 px-4">
				<div class="grid grid-col-1 lg:grid-cols-2 py-2 gap-2">
					<div class="font-bold uppercase">Volume Id</div>
					<div class="font-bold uppercase">Mount Dir</div>
				</div>
			</div>

			<div class="gap-4">
				{#each predefinedVolumes as storage}
					{#key storage.id}
						<Storage on:refresh={refreshStorage} {storage} />
					{/key}
				{/each}
			</div>
		{/if}
		{#if persistentStorages.length > 0}
			<div class="title" class:pt-10={predefinedVolumes.length > 0}>Custom Volumes</div>
		{/if}
		{#each persistentStorages as storage}
			{#key storage.id}
				<Storage on:refresh={refreshStorage} {storage} />
			{/key}
		{/each}
		{#if $appSession.isAdmin}
		<div class:pt-10={predefinedVolumes.length > 0}>
			Add New Volume <Explainer
				position="dropdown-bottom"
				explanation={$t('application.storage.persistent_storage_explainer')}
			/>
		</div>
	
		<Storage on:refresh={refreshStorage} isNew />
		{/if}
	</div>
</div>
