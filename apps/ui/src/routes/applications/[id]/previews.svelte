<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/previews`);
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
	export let containers: any;
	export let application: any;
	export let PRMRSecrets: any;
	export let applicationSecrets: any;
	import Secret from './_Secret.svelte';
	import { get, post } from '$lib/api';
	import { page } from '$app/stores';
	import Explainer from '$lib/components/Explainer.svelte';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';
	import { goto } from '$app/navigation';
	import { errorNotification, getDomain } from '$lib/common';

	const { id } = $page.params;
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets`);
		PRMRSecrets = [...data.secrets];
	}
	async function redeploy(container: any) {
		try {
			const { buildId } = await post(`/applications/${id}/deploy`, {
				pullmergeRequestId: container.pullmergeRequestId,
				branch: container.branch
			});
			toast.push('Deployment queued');
			if ($page.url.pathname.startsWith(`/applications/${id}/logs/build`)) {
				return window.location.assign(`/applications/${id}/logs/build?buildId=${buildId}`);
			} else {
				return await goto(`/applications/${id}/logs/build?buildId=${buildId}`, {
					replaceState: true
				});
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Preview Deployments
		</div>
		<span class="text-xs">{application.name} </span>
	</div>
</div>
<div class="mx-auto max-w-6xl px-6 pt-4">
	<div class="flex justify-center py-4 text-center">
		<Explainer
			customClass="w-full"
			text={applicationSecrets.length === 0
				? "You can add secrets to PR/MR deployments. Please add secrets to the application first. <br>Useful for creating <span class='text-green-500 font-bold'>staging</span> environments."
				: "These values overwrite application secrets in PR/MR deployments. <br>Useful for creating <span class='text-green-500 font-bold'>staging</span> environments."}
		/>
	</div>
	{#if applicationSecrets.length !== 0}
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
	{/if}
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
