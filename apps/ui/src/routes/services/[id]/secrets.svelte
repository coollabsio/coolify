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
	import Secret from './_Secret.svelte';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { t } from '$lib/translations';
	import pLimit from 'p-limit';
	import { addToast, appSession } from '$lib/store';
	import { saveSecret } from './utils';
	const limit = pLimit(1);

	const { id } = $page.params;
	let batchSecrets = '';

	async function refreshSecrets() {
		const data = await get(`/services/${id}/secrets`);
		secrets = [...data.secrets];
	}
	async function getValues() {
		if (!batchSecrets) return;
		const eachValuePair = batchSecrets.split('\n');
		const batchSecretsPairs = eachValuePair
			.filter((secret) => !secret.startsWith('#') && secret)
			.map((secret) => {
				const [name, ...rest] = secret.split('=');
				const value = rest.join('=');
				const cleanValue = value?.replaceAll('"', '') || '';
				return {
					name: name.trim(),
					value: cleanValue.trim(),
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

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Secrets</div>
	</div>
	<div class="overflow-x-auto">
		<table class="w-full border-separate text-left">
			<thead>
				<tr class="uppercase">
					<th scope="col">{$t('forms.name')}</th>
					<th scope="col uppercase">{$t('forms.value')}</th>
					<th scope="col uppercase" class="w-96 text-center">{$t('forms.action')}</th>
				</tr>
			</thead>
			<tbody class="space-y-2">
				{#each secrets as secret}
					{#key secret.id}
						<tr>
							<Secret
								name={secret.name}
								value={secret.value}
								readonly={secret.readOnly}
								on:refresh={refreshSecrets}
							/>
						</tr>
					{/key}
				{/each}
				<tr>
					<Secret isNewSecret on:refresh={refreshSecrets} />
				</tr>
			</tbody>
		</table>
	</div>
	{#if $appSession.isAdmin}
		<form on:submit|preventDefault={getValues} class="mb-12 w-full">
			<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 pt-10">
				<div class="flex flex-row space-x-2">
					<div class="title font-bold pb-3 ">Paste <code>.env</code> file</div>
					<button type="submit" class="btn btn-sm bg-primary">Add Secrets in Batch</button>
				</div>
			</div>

			<textarea
				placeholder={`PORT=1337\nPASSWORD=supersecret`}
				bind:value={batchSecrets}
				class="mb-2 min-h-[200px] w-full"
			/>
		</form>
	{/if}
</div>
