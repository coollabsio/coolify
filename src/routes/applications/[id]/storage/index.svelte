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
	import { get } from '$lib/api';
	import Explainer from '$lib/components/Explainer.svelte';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	async function refreshStorage() {
		const data = await get(`/applications/${id}/storage.json`);
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Persistent storage for <a href={application.fqdn} target="_blank"
			>{getDomain(application.fqdn)}</a
		>
	</div>
</div>

<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<div class="flex justify-center py-4 text-center">
		<Explainer customClass="w-full" text={$t('application.storage.persistent_storage_explainer')} />
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
