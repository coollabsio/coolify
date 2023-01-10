<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, stuff, url }) => {
		try {
			const response = await get(`/services/${params.id}/storages`);
			return {
				props: {
					template: stuff.template,
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
	export let template: any;
	import { page } from '$app/stores';
	import Storage from './_Storage.svelte';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';
	import { appSession } from '$lib/store';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/services/${id}/storages`);
		persistentStorages = [...data.persistentStorages];
	}
	let services = Object.keys(template).map((service) => {
		if (template[service]?.name) {
			return {
				name: template[service].name,
				id: service
			};
		} else {
			return service;
		}
	});
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
			<div class="title font-bold pb-3">
				Persistent Volumes <Explainer
					position="dropdown-bottom"
					explanation={$t('application.storage.persistent_storage_explainer')}
				/>
			</div>
		</div>
		{#if persistentStorages.filter((s) => s.predefined).length > 0}
			<div class="title">Predefined Volumes</div>
			<div class="w-full lg:px-0 px-4">
				<div class="grid grid-col-1 lg:grid-cols-2 pt-2 gap-2">
					<div class="font-bold uppercase">Container</div>
					<div class="font-bold uppercase">Volume ID : Mount Dir</div>
				</div>
			</div>

			{#each persistentStorages.filter((s) => s.predefined) as storage}
				{#key storage.id}
					<Storage on:refresh={refreshStorage} {storage} {services} />
				{/key}
			{/each}
		{/if}

		{#if persistentStorages.filter((s) => !s.predefined).length > 0}
			<div class="title" class:pt-10={persistentStorages.filter((s) => s.predefined).length > 0}>
				Custom Volumes
			</div>

			{#each persistentStorages.filter((s) => !s.predefined) as storage}
				{#key storage.id}
					<Storage on:refresh={refreshStorage} {storage} {services} />
				{/key}
			{/each}
		{/if}
		{#if $appSession.isAdmin}
			<div class="title" class:pt-10={persistentStorages.filter((s) => s.predefined).length > 0}>
				Add New Volume
			</div>
			<Storage on:refresh={refreshStorage} isNew {services} />
		{/if}
	</div>
</div>
