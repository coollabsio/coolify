<script lang="ts">
	export let source: any;
	export let settings: any;
	import Explainer from '$lib/components/Explainer.svelte';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';
	import { getAPIUrl, post } from '$lib/api';
	import { dev } from '$app/env';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { toast } from '@zerodevx/svelte-toast';

	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	const { id } = $page.params;

	let url = settings.fqdn ? settings.fqdn : window.location.origin;

	if (dev) {
		url = getAPIUrl();
	}
	let loading = false;

	let oauthIdEl: HTMLInputElement;
	let applicationType: string;
	if (!source.gitlabAppId) {
		source.gitlabApp = {
			oauthId: null,
			groupName: null,
			appId: null,
			appSecret: null
		};
	}
	$: selfHosted = source.htmlUrl !== 'https://gitlab.com' ;

	onMount(() => {
		oauthIdEl && oauthIdEl.focus();
	});

	async function handleSubmit() {
		if (loading) return;
		loading = true;
		if (!source.gitlabAppId) {
			// New GitLab App
			try {
				const { id } = await post(`/sources/new/gitlab`, {
					type: 'gitlab',
					name: source.name,
					htmlUrl: source.htmlUrl.replace(/\/$/, ''),
					apiUrl: source.apiUrl.replace(/\/$/, ''),
					oauthId: source.gitlabApp.oauthId,
					appId: source.gitlabApp.appId,
					appSecret: source.gitlabApp.appSecret,
					groupName: source.gitlabApp.groupName,
					customPort: source.customPort
				});
				const from = $page.url.searchParams.get('from');
				if (from) {
					return window.location.assign(from);
				}
				return window.location.assign(`/sources/${id}`);
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		} else {
			// Update GitLab App
			try {
				await post(`/sources/${id}`, {
					name: source.name,
					htmlUrl: source.htmlUrl.replace(/\/$/, ''),
					apiUrl: source.apiUrl.replace(/\/$/, ''),
					customPort: source.customPort
				});
				toast.push('Configuration saved.');
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		}
	}

	async function changeSettings() {
		const {
			htmlUrl,
			gitlabApp: { oauthId }
		} = source;
		const left = screen.width / 2 - 1020 / 2;
		const top = screen.height / 2 - 1000 / 2;
		const newWindow = open(
			`${htmlUrl}/oauth/applications/${oauthId}`,
			'GitLab',
			'resizable=1, scrollbars=1, fullscreen=0, height=1000, width=1020,top=' +
				top +
				', left=' +
				left +
				', toolbar=0, menubar=0, status=0'
		);
		const timer = setInterval(() => {
			if (newWindow?.closed) {
				clearInterval(timer);
			}
		}, 100);
	}
	async function checkOauthId() {
		if (source.gitlabApp?.oauthId) {
			try {
				await post(`/sources/${id}/check`, {
					oauthId: source.gitlabApp?.oauthId
				});
			} catch (error) {
				source.gitlabApp.oauthId = null;
				oauthIdEl.focus();
				return errorNotification(error);
			}
		}
	}
	function newApp() {
		switch (applicationType) {
			case 'user':
				window.open(`${source.htmlUrl}/-/profile/applications`);
				break;
			case 'group':
				if (!source.gitlabApp.groupName) {
					toast.push('Please enter a group name first.');
					return;
				}
				window.open(
					`${source.htmlUrl}/groups/${source.gitlabApp.groupName}/-/settings/applications`
				);
				break;
			case 'instance':
				break;
			default:
				break;
		}
	}
</script>

<div class="mx-auto max-w-4xl px-6">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-7 font-bold">
			<div class="title">General</div>
			{#if $appSession.isAdmin}
				<button
					type="submit"
					class:bg-orange-600={!loading}
					class:hover:bg-orange-500={!loading}
					disabled={loading}>{loading ? $t('forms.saving') : $t('forms.save')}</button
				>
				{#if source.gitlabAppId}
					<button on:click|preventDefault={changeSettings}
						>{$t('source.change_app_settings', { name: 'GitLab' })}</button
					>
				{:else}
					<button on:click|preventDefault|stopPropagation={newApp}>Create new GitLab App</button>
				{/if}
			{/if}
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			{#if !source.gitlabAppId}
				<div class="grid grid-cols-2 items-center">
					<label for="type" class="text-base font-bold text-stone-100">Application Type</label>
					<select name="type" id="type" class="w-96" bind:value={applicationType}>
						<option value="user">{$t('source.gitlab.user_owned')}</option>
						<option value="group">{$t('source.gitlab.group_owned')}</option>
						{#if source.htmlUrl !== 'https://gitlab.com'}
							<option value="instance">{$t('source.gitlab.self_hosted')}</option>
						{/if}
					</select>
				</div>

				{#if applicationType === 'group'}
					<div class="grid grid-cols-2 items-center">
						<label for="groupName" class="text-base font-bold text-stone-100">Group Name</label>
						<input
							name="groupName"
							id="groupName"
							required
							bind:value={source.gitlabApp.groupName}
						/>
					</div>
				{/if}
			{/if}

			<div class="grid grid-flow-row gap-2">
				<div class="mt-2 grid grid-cols-2 items-center">
					<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
					<input name="name" id="name" required bind:value={source.name} />
				</div>
			</div>
			{#if source.gitlabApp.groupName}
				<div class="grid grid-cols-2 items-center">
					<label for="groupName" class="text-base font-bold text-stone-100"
						>{$t('source.group_name')}</label
					>
					<input
						name="groupName"
						id="groupName"
						disabled={source.gitlabAppId}
						readonly={source.gitlabAppId}
						required
						bind:value={source.gitlabApp.groupName}
					/>
				</div>
			{/if}
			<div class="grid grid-cols-2 items-center">
				<label for="htmlUrl" class="text-base font-bold text-stone-100">HTML URL</label>
				<input
					name="htmlUrl"
					id="htmlUrl"
					required
					disabled={source.gitlabAppId}
					readonly={source.gitlabAppId}
					bind:value={source.htmlUrl}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="apiUrl" class="text-base font-bold text-stone-100">API URL</label>
				<input
					name="apiUrl"
					id="apiUrl"
					disabled={source.gitlabAppId}
					readonly={source.gitlabAppId}
					required
					bind:value={source.apiUrl}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="customPort" class="text-base font-bold text-stone-100">Custom SSH Port</label>
				<input
					name="customPort"
					id="customPort"
					disabled={!selfHosted}
					readonly={!selfHosted}
					required
					bind:value={source.customPort}
				/>
				<Explainer
					text="If you use a self-hosted version of Git, you can provide custom port for all the Git related actions."
				/>
			</div>
			<div class="grid grid-cols-2 items-start">
				<div class="flex-col">
					<label for="oauthId" class="pt-2 text-base font-bold text-stone-100"
						>{$t('source.oauth_id')}</label
					>
					{#if !source.gitlabAppId}
						<Explainer text={$t('source.oauth_id_explainer')} />
					{/if}
				</div>
				<input
					disabled={source.gitlabAppId}
					readonly={source.gitlabAppId}
					on:change={checkOauthId}
					bind:this={oauthIdEl}
					name="oauthId"
					id="oauthId"
					type="number"
					required
					bind:value={source.gitlabApp.oauthId}
				/>
			</div>

			<div class="grid grid-cols-2 items-center">
				<label for="appId" class="text-base font-bold text-stone-100"
					>{$t('source.application_id')}</label
				>
				<input
					name="appId"
					id="appId"
					disabled={source.gitlabAppId}
					readonly={source.gitlabAppId}
					required
					bind:value={source.gitlabApp.appId}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="appSecret" class="text-base font-bold text-stone-100"
					>{$t('index.secret')}</label
				>
				<CopyPasswordField
					disabled={source.gitlabAppId}
					readonly={source.gitlabAppId}
					isPasswordField={true}
					name="appSecret"
					id="appSecret"
					required
					bind:value={source.gitlabApp.appSecret}
				/>
			</div>
		</div>
	</form>
	{#if !source.gitlabAppId}
		<Explainer
			customClass="w-full"
			text="<span class='font-bold text-base text-white'>Scopes required:</span> 	
<br>- <span class='text-orange-500 font-bold'>api</span> (Access the authenticated user's API)
<br>- <span class='text-orange-500 font-bold'>read_repository</span> (Allows read-only access to the repository)
<br>- <span class='text-orange-500 font-bold'>email</span> (Allows read-only access to the user's primary email address using OpenID Connect)
<br>
<br>For extra security, you can set <span class='text-orange-500 font-bold'>Expire Access Tokens</span>
<br><br>Webhook URL: <span class='text-orange-500 font-bold'>{url}/webhooks/gitlab</span>"
		/>
	{/if}
</div>
