<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/applications/${params.id}/previews.json`;
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
	export let containers;
	export let application;
	export let PRMRSecrets;
	export let applicationSecrets;
	import { getDomain } from '$lib/components/common';
	import Secret from '../secrets/_Secret.svelte';
	import { get, post } from '$lib/api';
	import { page } from '$app/stores';
	import Explainer from '$lib/components/Explainer.svelte';
	import { errorNotification } from '$lib/form';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets.json`);
		PRMRSecrets = [...data.secrets];
	}
	async function redeploy(container) {
		try {
			await post(`/applications/${id}/deploy.json`, {
				pullmergeRequestId: container.pullmergeRequestId,
				branch: container.branch
			});
			toast.push('Application redeployed queued.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Previews for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>

{#if applicationSecrets.length !== 0}
	<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
		<table class="mx-auto border-separate text-left">
			<thead>
				<tr class="h-12">
					<th scope="col">{$t('forms.name')}</th>
					<th scope="col">{$t('forms.value')}</th>
					<th scope="col" class="w-64 text-center"
						>{$t('application.preview.need_during_buildtime')}</th
					>
					<th scope="col" class="w-96 text-center">{$t('forms.action')}</th>
				</tr>
			</thead>
			<tbody>
				{#each applicationSecrets as secret}
					{#key secret.id}
						<tr>
							<Secret
								PRMRSecret={PRMRSecrets.find((s) => s.name === secret.name)}
								isPRMRSecret
								name={secret.name}
								value={secret.value}
								isBuildSecret={secret.isBuildSecret}
								on:refresh={refreshSecrets}
							/>
						</tr>
					{/key}
				{/each}
			</tbody>
		</table>
	</div>
{/if}
<div class="flex justify-center py-4 text-center">
	<Explainer
		customClass="w-full"
		text={applicationSecrets.length === 0
			? $t('application.preview.setup_secret_app_first')
			: $t('application.preview.values_overwriting_app_secrets')}
	/>
</div>
<div class="mx-auto max-w-4xl py-10">
	<div class="flex flex-wrap justify-center space-x-2">
		{#if containers.length > 0}
			{#each containers as container}
				<a href={container.fqdn} class="p-2 no-underline" target="_blank">
					<div class="box-selection text-center hover:border-transparent hover:bg-coolgray-200">
						<div class="truncate text-center text-xl font-bold">{getDomain(container.fqdn)}</div>
					</div>
				</a>
				<div class="flex items-center justify-center">
					<button class="bg-coollabs hover:bg-coollabs-100" on:click={() => redeploy(container)}
						>{$t('application.preview.redeploy')}</button
					>
				</div>
			{/each}
		{:else}
			<div class="flex-col">
				<div class="text-center font-bold text-xl">
					{$t('application.preview.no_previews_available')}
				</div>
			</div>
		{/if}
	</div>
</div>
