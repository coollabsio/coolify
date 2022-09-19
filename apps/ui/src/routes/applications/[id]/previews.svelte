<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff, url }) => {
		try {
			return {
				props: {
					application: stuff.application
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
	export let application: any;
	import Secret from './_Secret.svelte';
	import { get, post } from '$lib/api';
	import { page } from '$app/stores';
	import { t } from '$lib/translations';
	import { goto } from '$app/navigation';
	import { errorNotification, getDomain } from '$lib/common';
	import { onMount } from 'svelte';
	import Loading from '$lib/components/Loading.svelte';
	import { addToast } from '$lib/store';
	import SimpleExplainer from '$lib/components/SimpleExplainer.svelte';

	const { id } = $page.params;

	let containers: any;
	let PRMRSecrets: any;
	let applicationSecrets: any;
	let loading = {
		init: true,
		removing: false
	};
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets`);
		PRMRSecrets = [...data.secrets];
	}
	async function removeApplication(container: any) {
		try {
			loading.removing = true;
			await post(`/applications/${id}/stop/preview`, {
				pullmergeRequestId: container.pullmergeRequestId
			});
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function redeploy(container: any) {
		try {
			const { buildId } = await post(`/applications/${id}/deploy`, {
				pullmergeRequestId: container.pullmergeRequestId,
				branch: container.branch
			});
			addToast({
				message: 'Deployment queued',
				type: 'success'
			});
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
	onMount(async () => {
		try {
			loading.init = true;
			const response = await get(`/applications/${id}/previews`);
			containers = response.containers;
			PRMRSecrets = response.PRMRSecrets;
			applicationSecrets = response.applicationSecrets;
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.init = false;
		}
	});
</script>

{#if loading.init}
	<Loading />
{:else}
	<div class="mx-auto max-w-6xl px-6 pt-4">
		<div class="flex flex-col justify-center py-4 text-center">
			<h1 class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block font-bold mb-4">
				Preview Deployments
			</h1>
			<SimpleExplainer
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
						<div class="box-selection text-center hover:border-transparent hover:bg-green-600">
							<div class="truncate text-center text-xl font-bold">{getDomain(container.fqdn)}</div>
						</div>
					</a>
					<div class="flex items-center justify-center">
						<button
							class="btn btn-sm bg-coollabs hover:bg-coollabs-100"
							on:click={() => redeploy(container)}>{$t('application.preview.redeploy')}</button
						>
					</div>
					<div class="flex items-center justify-center">
						<button
							class="btn btn-sm"
							class:bg-red-600={!loading.removing}
							class:hover:bg-red-500={!loading.removing}
							disabled={loading.removing}
							on:click={() => removeApplication(container)}
							>{loading.removing ? 'Removing...' : 'Remove Application'}
						</button>
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
{/if}
