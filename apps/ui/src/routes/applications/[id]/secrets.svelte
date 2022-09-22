<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/secrets`);
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
	export let secrets: any;
		import pLimit from 'p-limit';
	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { saveSecret } from './utils';
	import { addToast } from '$lib/store';
	import Secret from './_Secret.svelte';

	const limit = pLimit(1);
	const { id } = $page.params;

	let batchSecrets = '';
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets`);
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
					name,
					value: cleanValue,
					isNew: !secrets.find((secret: any) => name === secret.name)
				};
			});

		await Promise.all(
			batchSecretsPairs.map(({ name, value, isNew }) =>
				limit(() => saveSecret({ name, value, applicationId: id, isNew }))
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
	{#each secrets as secret}
		{#key secret.id}
			<Secret
				length={secrets.length}
				name={secret.name}
				value={secret.value}
				isBuildSecret={secret.isBuildSecret}
				on:refresh={refreshSecrets}
			/>
		{/key}
	{/each}
	<Secret isNewSecret on:refresh={refreshSecrets} />

</div>
<form on:submit|preventDefault={getValues} class="mb-12 w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 pt-10">
		<div class="flex flex-row space-x-2">
			<div class="title font-bold pb-3 ">Paste <code>.env</code> file</div>
			<button class="btn btn-sm bg-primary" type="submit">Add Secrets in Batch</button>
		</div>
	</div>

	<textarea placeholder={`PORT=1337\nPASSWORD=supersecret`} bind:value={batchSecrets} class="mb-2 min-h-[200px] w-full" />
</form>
