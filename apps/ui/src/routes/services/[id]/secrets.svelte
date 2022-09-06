<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/services/${params.id}/secrets`);
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
	export let secrets: any;
	export let service: any;
	import Secret from './_Secret.svelte';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import pLimit from 'p-limit';
	import ServiceLinks from './_ServiceLinks.svelte';
	import { addToast } from '$lib/store';
	import { saveSecret } from './utils';
	const limit = pLimit(1);

	const { id } = $page.params;
	let batchSecrets = '';

	async function refreshSecrets() {
		const data = await get(`/services/${id}/secrets`);
		secrets = [...data.secrets];
	}
	async function getValues(e: any) {
		e.preventDefault();
		const eachValuePair = batchSecrets.split('\n');
		const batchSecretsPairs = eachValuePair
			.filter((secret) => !secret.startsWith('#') && secret)
			.map((secret) => {
				const [name, ...rest] = secret.split('=');
				const value = rest.join('=');
				const cleanValue = value?.replaceAll('"', '') || '';
				return {
					name,
					value: cleanValue,
					isNew: !secrets.find((secret: any) => name === secret.name)
				};
			});

		await Promise.all(
			batchSecretsPairs.map(({ name, value, isNew }) =>
				limit(() => saveSecret({ name, value, serviceId: id, isNew }))
			)
		);
		batchSecrets = '';
		await refreshSecrets();
		addToast({
			message: 'Secrets saved.',
			type: 'success'
		});
	}
</script>

<div
	class="flex items-center space-x-2 p-5 px-6 font-bold"
	class:p-5={service.fqdn}
	class:p-6={!service.fqdn}
>
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			{$t('application.secret')}
		</div>
		<span class="text-xs">{service.name}</span>
	</div>
	{#if service.fqdn}
		<a
			href={service.fqdn}
			target="_blank"
			class="icons tooltip-bottom flex items-center bg-transparent text-sm"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="h-6 w-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
				<line x1="10" y1="14" x2="20" y2="4" />
				<polyline points="15 4 20 4 20 9" />
			</svg></a
		>
	{/if}

	<ServiceLinks {service} />
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
	<h2 class="title my-6 font-bold">Paste .env file</h2>
	<form on:submit|preventDefault={getValues} class="mb-12 w-full">
		<textarea bind:value={batchSecrets} class="mb-2 min-h-[200px] w-full" />
		<button class="btn btn-sm bg-applications" type="submit">Batch add secrets</button>
	</form>
</div>
