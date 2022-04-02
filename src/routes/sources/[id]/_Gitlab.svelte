<script lang="ts">
	export let source;
	import Explainer from '$lib/components/Explainer.svelte';
	import { enhance, errorNotification } from '$lib/form';
	import { page, session } from '$app/stores';
	import { onMount } from 'svelte';
	import { post } from '$lib/api';
	import { browser } from '$app/env';
	const { id } = $page.params;

	let loading = false;
	let oauthIdEl;
	let payload = {
		oauthId: undefined,
		groupName: undefined,
		appId: undefined,
		appSecret: undefined,
		applicationType: 'user'
	};
	onMount(() => {
		oauthIdEl && oauthIdEl.focus();
	});
	async function handleSubmitSave() {
		loading = true;
		try {
			return await post(`/sources/${id}.json`, { name: source.name });
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
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
		if (payload.oauthId) {
			try {
				await post(`/sources/${id}/check.json`, { oauthId: payload.oauthId });
			} catch ({ error }) {
				payload.oauthId = null;
				oauthIdEl.focus();
				return errorNotification(error);
			}
		}
	}
	function newApp() {
		switch (payload.applicationType) {
			case 'user':
				window.open(`${source.htmlUrl}/-/profile/applications`);
				break;
			case 'group':
				window.open(`${source.htmlUrl}/groups/${payload.groupName}/-/settings/applications`);
				break;
			case 'instance':
				break;
			default:
				break;
		}
	}
	async function handleSubmit() {
		loading = true;
		try {
			await post(`/sources/${id}/gitlab.json`, { ...payload });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

{#if !source.gitlabApp?.appId}
	<form class="grid grid-flow-row gap-2 py-4" on:submit|preventDefault={newApp}>
		<div class="grid grid-cols-2 items-center">
			<label for="type">GitLab Application Type</label>
			<select name="type" id="type" class="w-96" bind:value={payload.applicationType}>
				<option value="user">User owned application</option>
				<option value="group">Group owned application</option>
				{#if source.htmlUrl !== 'https://gitlab.com'}
					<option value="instance">Instance-wide application (self-hosted)</option>
				{/if}
			</select>
		</div>
		{#if payload.applicationType === 'group'}
			<div class="grid grid-cols-2 items-center">
				<label for="groupName">Group Name</label>
				<input name="groupName" id="groupName" required bind:value={payload.groupName} />
			</div>
		{/if}

		<div class="w-full pt-10 text-center">
			<button class="w-96 bg-orange-600 hover:bg-orange-500" type="submit"
				>Register new OAuth application on GitLab</button
			>
		</div>

		<Explainer
			customClass="w-full"
			text="<span class='font-bold text-base text-white'>Scopes required:</span> 	
	<br>- <span class='text-orange-500 font-bold'>api</span> (Access the authenticated user's API)
	<br>- <span class='text-orange-500 font-bold'>read_repository</span> (Allows read-only access to the repository)
	<br>- <span class='text-orange-500 font-bold'>email</span> (Allows read-only access to the user's primary email address using OpenID Connect)
	<br>
	<br>For extra security, you can set Expire access tokens!
	<br><br>Webhook URL: <span class='text-orange-500 font-bold'>{browser
				? window.location.origin
				: ''}/webhooks/gitlab</span>
	<br>But if you will set a custom domain name for Coolify, use that instead."
		/>
	</form>
	<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4 pt-10">
		<div class="flex h-8 items-center space-x-2">
			<div class="text-xl font-bold text-white">Configuration</div>
			<button
				type="submit"
				class:bg-orange-600={!loading}
				class:hover:bg-orange-500={!loading}
				disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
			>
		</div>

		<div class="grid grid-cols-2 items-start">
			<div class="flex-col">
				<label for="oauthId" class="pt-2">OAuth ID</label>
				<Explainer
					text="The OAuth ID is the unique identifier of the GitLab application. <br>You can find it <span class='font-bold text-orange-600' >in the URL</span> of your GitLab OAuth Application."
				/>
			</div>
			<input
				on:change={checkOauthId}
				bind:this={oauthIdEl}
				name="oauthId"
				id="oauthId"
				type="number"
				required
				bind:value={payload.oauthId}
			/>
		</div>
		{#if payload.applicationType === 'group'}
			<div class="grid grid-cols-2 items-center">
				<label for="groupName">Group Name</label>
				<input name="groupName" id="groupName" required bind:value={payload.groupName} />
			</div>
		{/if}
		<div class="grid grid-cols-2 items-center">
			<label for="appId">Application ID</label>
			<input name="appId" id="appId" required bind:value={payload.appId} />
		</div>
		<div class="grid grid-cols-2 items-center">
			<label for="appSecret">Secret</label>
			<input
				name="appSecret"
				id="appSecret"
				type="password"
				required
				bind:value={payload.appSecret}
			/>
		</div>
	</form>
{:else}
	<div class="mx-auto max-w-4xl px-6">
		<form on:submit|preventDefault={handleSubmitSave} class="py-4">
			<div class="flex space-x-1 pb-5 font-bold">
				<div class="title">General</div>
				{#if $session.isAdmin}
					<button
						type="submit"
						class:bg-orange-600={!loading}
						class:hover:bg-orange-500={!loading}
						disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
					>
					<button on:click|preventDefault={changeSettings}>Change GitLab App Settings</button>
				{/if}
			</div>
			<div class="grid grid-flow-row gap-2 px-10">
				<div class="mt-2 grid grid-cols-2 items-center">
					<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
					<input name="name" id="name" required bind:value={source.name} />
				</div>
			</div>
		</form>
	</div>
{/if}
