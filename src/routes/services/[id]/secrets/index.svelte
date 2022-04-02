<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/services/${params.id}/secrets.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					service: stuff.service,
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
	export let secrets;
	export let service;
	import Secret from './_Secret.svelte';
	import { getDomain } from '$lib/components/common';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';

	const { id } = $page.params;

	async function refreshSecrets() {
		const data = await get(`/services/${id}/secrets.json`);
		secrets = [...data.secrets];
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		{$t('application.secret')}
		{#if service.fqdn}
			<a href={service.fqdn} target="_blank">{getDomain(service.fqdn)}</a>
		{/if}
	</div>
</div>
<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<table class="mx-auto border-separate text-left">
		<thead>
			<tr class="h-12">
				<th scope="col">{$t('forms.name')}</th>
				<th scope="col">{$t('forms.value')}</th>
				<th scope="col" class="w-96 text-center">{$t('forms.action')}</th>
			</tr>
		</thead>
		<tbody>
			{#each secrets as secret}
				{#key secret.id}
					<tr>
						<Secret name={secret.name} value={secret.value} on:refresh={refreshSecrets} />
					</tr>
				{/key}
			{/each}
			<tr>
				<Secret isNewSecret on:refresh={refreshSecrets} />
			</tr>
		</tbody>
	</table>
</div>
