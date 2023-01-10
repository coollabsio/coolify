<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			if (stuff?.application?.id) {
				return {
					props: {
						application: stuff.application,
						settings: stuff.settings
					}
				};
			}
			const response = await get(`/applications/${params.id}`);
			return {
				props: {
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
	export let application: any;
	export let settings: any;
	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import {
		addToast,
		appSession,
		checkIfDeploymentEnabledApplications,
		setLocation,
		status,
		isDeploymentEnabled
	} from '$lib/store';
	import { t } from '$lib/translations';
	import { errorNotification, getDomain, notNodeDeployments, staticDeployments } from '$lib/common';
	import Setting from '$lib/components/Setting.svelte';

	const { id } = $page.params;

	let previews = application.settings.previews;
	let dualCerts = application.settings.dualCerts;
	let autodeploy = application.settings.autodeploy;
	let isBot = application.settings.isBot;
	let isDBBranching = application.settings.isDBBranching;

	async function changeSettings(name: any) {
		if (!$appSession.isAdmin) return
		if (name === 'previews') {
			previews = !previews;
		}
		if (name === 'dualCerts') {
			dualCerts = !dualCerts;
		}
		if (name === 'autodeploy') {
			autodeploy = !autodeploy;
		}
		if (name === 'isBot') {
			if ($status.application.isRunning) return;
			isBot = !isBot;
			application.settings.isBot = isBot;
			application.fqdn = null;
			setLocation(application, settings);
		}
		if (name === 'isDBBranching') {
			isDBBranching = !isDBBranching;
		}
		try {
			await post(`/applications/${id}/settings`, {
				previews,
				dualCerts,
				isBot,
				autodeploy,
				isDBBranching,
				branch: application.branch,
				projectId: application.projectId
			});
			return addToast({
				message: $t('application.settings_saved'),
				type: 'success'
			});
		} catch (error) {
			if (name === 'previews') {
				previews = !previews;
			}
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			if (name === 'autodeploy') {
				autodeploy = !autodeploy;
			}
			if (name === 'isBot') {
				isBot = !isBot;
			}
			if (name === 'isDBBranching') {
				isDBBranching = !isDBBranching;
			}
			return errorNotification(error);
		} finally {
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications(application);
		}
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6  space-x-2">
			<div class="title font-bold pb-3">Features</div>
		</div>
		<div class="px-4 lg:pb-10 pb-6">
			{#if !application.settings.isPublicRepository}
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="autodeploy"
						isCenter={false}
						bind:setting={autodeploy}
						disabled={!$appSession.isAdmin}
						on:click={() => changeSettings('autodeploy')}
						title={$t('application.enable_automatic_deployment')}
						description={$t('application.enable_auto_deploy_webhooks')}
					/>
				</div>
				{#if !application.settings.isBot && !application.simpleDockerfile}
					<div class="grid grid-cols-2 items-center">
						<Setting
							id="previews"
							isCenter={false}
							bind:setting={previews}
							disabled={!$appSession.isAdmin}
							on:click={() => changeSettings('previews')}
							title={$t('application.enable_mr_pr_previews')}
							description={$t('application.enable_preview_deploy_mr_pr_requests')}
						/>
					</div>
				{/if}
			{:else}
				No features available for this application
			{/if}
		</div>
	</div>
</div>
