<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	let secrets = data.secrets;
	import Secret from './components/Secret.svelte';
	import { page } from '$app/stores';
	import pLimit from 'p-limit';
	import { addToast, appSession, trpc } from '$lib/store';
	import { saveSecret } from './utils';
	const limit = pLimit(1);

	const { id } = $page.params;
	let batchSecrets = '';

	async function refreshSecrets() {
		const { data } = await trpc.services.getSecrets.query({ id });
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
					<th scope="col">Name</th>
					<th scope="col uppercase">Value</th>
					<th scope="col uppercase" class="w-96 text-center">Action</th>
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
