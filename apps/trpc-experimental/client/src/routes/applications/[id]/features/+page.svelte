<script lang="ts">
	import type { PageParentData } from './$types';

	export let data: PageParentData;
	const application = data.application.data;
	const settings = data.settings.data;
	import { page } from '$app/stores';
	const { id } = $page.params;
	import {
		addToast,
		appSession,
		checkIfDeploymentEnabledApplications,
		setLocation,
		status,
		isDeploymentEnabled,
		trpc
	} from '$lib/store';
	import { errorNotification } from '$lib/common';
	import Setting from '$lib/components/Setting.svelte';

	let previews = application.settings.previews;
	let dualCerts = application.settings.dualCerts;
	let autodeploy = application.settings.autodeploy;
	let isBot = application.settings.isBot;
	let isDBBranching = application.settings.isDBBranching;

	async function changeSettings(name: any) {
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
			await trpc.applications.saveSettings.mutate({
				id,
				previews,
				dualCerts,
				isBot,
				autodeploy,
				isDBBranching
			});

			return addToast({
				message: 'Settings saved',
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
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);
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
						on:click={() => changeSettings('autodeploy')}
						title="Enable Automatic Deployment"
						description="Enable automatic deployment through webhooks."
					/>
				</div>
				{#if !application.settings.isBot && !application.simpleDockerfile}
					<div class="grid grid-cols-2 items-center">
						<Setting
							id="previews"
							isCenter={false}
							bind:setting={previews}
							on:click={() => changeSettings('previews')}
							title="Enable MR/PR Previews"
							description="Enable preview deployments from pull or merge requests."
						/>
					</div>
				{/if}
			{:else}
				No features available for this application
			{/if}
		</div>
	</div>
</div>
