<script lang="ts">
	export let source: any;
	export let settings: any;
	import { page } from '$app/stores';
	import { getAPIUrl, getWebhookUrl, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { dashify, errorNotification, getDomain } from '$lib/common';
	import { addToast, appSession } from '$lib/store';
	import { dev } from '$app/env';
	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';

	const { id } = $page.params;

	$: selfHosted = source.htmlUrl !== 'https://github.com';

	let loading = false;

	async function handleSubmit() {
		loading = true;
		try {
			await post(`/sources/${id}`, {
				name: source.name,
				htmlUrl: source.htmlUrl.replace(/\/$/, ''),
				apiUrl: source.apiUrl.replace(/\/$/, ''),
				isSystemWide: source.isSystemWide
			});
			return addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}

	async function newGithubApp() {
		loading = true;
		try {
			const { id } = await post(`/sources/new/github`, {
				type: 'github',
				name: source.name,
				htmlUrl: source.htmlUrl.replace(/\/$/, ''),
				apiUrl: source.apiUrl.replace(/\/$/, ''),
				organization: source.organization,
				customPort: source.customPort,
				isSystemWide: source.isSystemWide
			});
			const { organization, htmlUrl } = source;
			const { fqdn, ipv4, ipv6 } = settings;
			const host = dev ? getAPIUrl() : fqdn ? fqdn : `http://${ipv4 || ipv6}` || '';
			const domain = getDomain(fqdn);

			let url = 'settings/apps/new';
			if (organization) url = `organizations/${organization}/settings/apps/new`;
			const name = dashify(domain) || 'app';
			const data = JSON.stringify({
				name: `coolify-${name}`,
				url: host,
				hook_attributes: {
					url: dev ? getWebhookUrl('github') : `${host}/webhooks/github/events`
				},
				redirect_url: `${host}/webhooks/github`,
				callback_urls: [`${host}/login/github/app`],
				public: false,
				request_oauth_on_install: false,
				setup_url: `${host}/webhooks/github/install?gitSourceId=${id}`,
				setup_on_update: true,
				default_permissions: {
					contents: 'read',
					metadata: 'read',
					pull_requests: 'read',
					emails: 'read'
				},
				default_events: ['pull_request', 'push']
			});
			const form = document.createElement('form');
			form.setAttribute('method', 'post');
			form.setAttribute('action', `${htmlUrl}/${url}?state=${id}`);
			const input = document.createElement('input');
			input.setAttribute('id', 'manifest');
			input.setAttribute('name', 'manifest');
			input.setAttribute('type', 'hidden');
			input.setAttribute('value', data);
			form.appendChild(input);
			document.getElementsByTagName('body')[0].appendChild(form);
			form.submit();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function changeSettings(name: any, save: boolean) {
		if ($appSession.teamId === '0') {
			if (name === 'isSystemWide') {
				source.isSystemWide = !source.isSystemWide;
			}
			if (save) {
				await handleSubmit();
			}
		}
	}
</script>

<div class="mx-auto max-w-6xl lg:px-6 px-3">
	{#if !source.githubAppId}
		<form on:submit|preventDefault={newGithubApp} class="py-4">
			<div class="grid gap-1 lg:grid-flow-col pb-7">
				<h1 class="title">General</h1>
				{#if !source.githubAppId}
					<div class="w-full flex flex-rpw justify-end">
						<button class="btn btn-sm bg-sources mt-5 w-full lg:w-fit" type="submit"
							>Save & Redirect to GitHub</button
						>
					</div>
				{/if}
			</div>
			<div class="grid gap-2 grid-cols-2 auto-rows-max">
				<label for="name">Name</label>
				<input class="w-full" name="name" id="name" required bind:value={source.name} />
				<label for="htmlUrl">HTML URL</label>
				<input class="w-full" name="htmlUrl" id="htmlUrl" required bind:value={source.htmlUrl} />
				<label for="apiUrl">API URL</label>
				<input class="w-full" name="apiUrl" id="apiUrl" required bind:value={source.apiUrl} />
				<label for="customPort"
					>Custom SSH Port <Explainer
						explanation={'If you use a self-hosted version of Git, you can provide custom port for all the Git related actions.'}
					/></label
				>
				<input
					class="w-full"
					name="customPort"
					id="customPort"
					disabled={!selfHosted || source.githubAppId}
					readonly={!selfHosted || source.githubAppId}
					required
					value={source.customPort}
				/>
				<label for="organization" class="pt-2"
					>Organization
					<Explainer
						explanation={"Fill it if you would like to use an organization's as your Git Source. Otherwise your user will be used."}
					/></label
				>
				<input
					class="w-full"
					name="organization"
					id="organization"
					placeholder="eg: coollabsio"
					bind:value={source.organization}
				/>
				<Setting
					customClass="pt-4"
					id="autodeploy"
					isCenter={false}
					bind:setting={source.isSystemWide}
					on:click={() => changeSettings('isSystemWide', false)}
					title="System Wide Git"
					description="System Wide Git are available to all the users in your Coolify instance. <br><br> <span class='font-bold text-warning'>Use with caution, as it can be a security risk.</span>"
				/>
			</div>
		</form>
	{:else if source.githubApp?.installationId}
		<form on:submit|preventDefault={handleSubmit} class="py-4">
			<div class="flex lg:flex-row lg:justify-between flex-col space-y-3 w-full lg:items-center">
				<h1 class="title">{$t('general')}</h1>
				{#if $appSession.isAdmin && $appSession.teamId === '0'}
					<div
						class="flex flex-col lg:flex-row lg:space-x-4 lg:w-fit space-y-2 lg:space-y-0 w-full"
					>
						<button class="btn btn-sm bg-sources" type="submit" disabled={loading}
							>{loading ? 'Saving...' : 'Save'}</button
						>
						<a
							class="btn btn-sm"
							href={`${source.htmlUrl}/${
								source.htmlUrl === 'https://github.com' ? 'apps' : 'github-apps'
							}/${source.githubApp.name}/installations/new`}
							>{$t('source.change_app_settings', { name: 'GitHub' })}</a
						>
					</div>
				{/if}
			</div>
			<div class="grid gap-2 grid-cols-2 auto-rows-max mt-4">
				<label for="name">{$t('forms.name')}</label>
				<input
					class="w-full"
					name="name"
					id="name"
					required
					bind:value={source.name}
					disabled={!$appSession.isAdmin}
				/>
				<label for="htmlUrl">HTML URL</label>
				<input
					class="w-full"
					name="htmlUrl"
					id="htmlUrl"
					disabled={source.githubAppId}
					readonly={source.githubAppId}
					required
					bind:value={source.htmlUrl}
				/>
				<label for="apiUrl">API URL</label>
				<input
					class="w-full"
					name="apiUrl"
					id="apiUrl"
					required
					disabled={source.githubAppId}
					readonly={source.githubAppId}
					bind:value={source.apiUrl}
				/>
				<label for="customPort"
					>Custom SSH Port <Explainer
						explanation="If you use a self-hosted version of Git, you can provide custom port for all the Git related actions."
					/></label
				>
				<input
					class="w-full"
					name="customPort"
					id="customPort"
					disabled={!selfHosted}
					readonly={!selfHosted}
					required
					value={source.customPort}
				/>
				<label for="organization" class="pt-2">Organization</label>
				<input
					class="w-full"
					readonly
					disabled
					name="organization"
					id="organization"
					placeholder="eg: coollabsio"
					bind:value={source.organization}
				/>
				{#if $appSession.isAdmin}
					<Setting
						customClass="pt-4"
						id="autodeploy"
						isCenter={false}
						disabled={$appSession.teamId !== '0'}
						bind:setting={source.isSystemWide}
						on:click={() => changeSettings('isSystemWide', true)}
						title="System Wide Git Source"
						description="System Wide Git Sources are available to all the users in your Coolify instance. <br><br> <span class='font-bold text-warning'>Use with caution, as it can be a security risk.</span>"
					/>
				{/if}
			</div>
		</form>
	{:else}
		<div class="text-center">
			<a
				href={`${source.htmlUrl}/${
					source.htmlUrl === 'https://github.com' ? 'apps' : 'github-apps'
				}/${source.githubApp.name}/installations/new`}
			>
				<button class="box-selection bg-sources text-xl font-bold">Install Repositories</button></a
			>
		</div>
	{/if}
</div>
