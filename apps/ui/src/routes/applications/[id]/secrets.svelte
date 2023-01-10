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
	export let application: any;
	export let previewSecrets: any;
	import pLimit from 'p-limit';
	import { page } from '$app/stores';
	import { get, post, put } from '$lib/api';
	import { addToast, appSession } from '$lib/store';
	import Secret from './_Secret.svelte';
	import PreviewSecret from './_PreviewSecret.svelte';
	import { errorNotification } from '$lib/common';
	import Explainer from '$lib/components/Explainer.svelte';

	const limit = pLimit(1);
	const { id } = $page.params;

	let batchSecrets = '';
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets`);
		previewSecrets = [...data.previewSecrets];
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
				return {
					name: name.trim(),
					value: value.trim(),
					createSecret: !secrets.find((secret: any) => name === secret.name)
				};
			});

		await Promise.all(
			batchSecretsPairs.map(({ name, value, createSecret }) =>
				limit(async () => {
					try {
						if (!name || !value) return;
						if (createSecret) {
							await post(`/applications/${id}/secrets`, {
								name,
								value
							});
							addToast({
								message: 'Secret created.',
								type: 'success'
							});
						} else {
							await put(`/applications/${id}/secrets`, {
								name,
								value
							});
							addToast({
								message: 'Secret updated.',
								type: 'success'
							});
						}
					} catch (error) {
						return errorNotification(error);
					}
				})
			)
		);
		batchSecrets = '';
		await refreshSecrets();
	}
</script>

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Secrets</div>
	</div>
	{#each secrets as secret, index}
		{#key secret.id}
			<Secret
				{index}
				length={secrets.length}
				name={secret.name}
				value={secret.value}
				isBuildSecret={secret.isBuildSecret}
				on:refresh={refreshSecrets}
			/>
		{/key}
	{/each}
	{#if $appSession.isAdmin}
		<div class="lg:pt-0 pt-10">
			<Secret on:refresh={refreshSecrets} length={secrets.length} isNewSecret />
		</div>
	{/if}
	{#if !application.settings.isBot && !application.simpleDockerfile}
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
			<div class="title font-bold pb-3 pt-8">
				Preview Secrets <Explainer
					explanation="These values overwrite application secrets in PR/MR deployments. <br>Useful for creating <span class='text-green-500 font-bold'>staging</span> environments."
				/>
			</div>
		</div>
		{#if previewSecrets.length !== 0}
			{#each previewSecrets as secret, index}
				{#key index}
					<PreviewSecret
						{index}
						length={secrets.length}
						name={secret.name}
						value={secret.value}
						isBuildSecret={secret.isBuildSecret}
						on:refresh={refreshSecrets}
					/>
				{/key}
			{/each}
		{:else}
			Add secrets first to see Preview Secrets.
		{/if}
	{/if}
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
