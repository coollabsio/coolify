<script lang="ts">
	export let source;
	import Explainer from '$lib/components/Explainer.svelte';
	import { enhance } from '$lib/form';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';
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
		oauthIdEl.focus();
	});
	async function checkOauthId() {
		if (payload.oauthId) {
			const form = new FormData();
			form.append('oauthId', payload.oauthId);
			const response = await fetch(`/sources/${id}/check.json`, {
				method: 'post',
				body: form
			});
			if (response.ok) {
				payload.oauthId = '';
				alert(
					'OAuthID is already used by another GitLab OAuth Application. Contact the administrator of that OAuth Application.'
				);
				oauthIdEl.focus();
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
				// TODO: This is not correct
				// window.location.assign(`${source.htmlUrl}/-/profile/applications`);
				break;
			default:
				break;
		}
	}
</script>

<div class="flex flex-col justify-center pb-8">
	{#if !source.gitlabApp?.appId}
		<form class="grid grid-flow-row gap-2 py-4" on:submit|preventDefault={newApp}>
			<div class="grid grid-cols-3 items-center">
				<label for="type">GitLab Application Type</label>
				<div class="col-span-2">
					<select name="type" id="type" class="w-96" bind:value={payload.applicationType}>
						<option value="user">User owned application</option>
						<option value="group">Group owned application</option>
						{#if source.htmlUrl !== 'https://gitlab.com'}
							<option value="instance">Instance-wide application (self-hosted)</option>
						{/if}
					</select>
				</div>
			</div>
			{#if payload.applicationType === 'group'}
				<div class="grid grid-cols-3 items-center">
					<label for="groupName">Group Name</label>
					<div class="col-span-2">
						<input name="groupName" id="groupName" required bind:value={payload.groupName} />
					</div>
				</div>
			{/if}

			<div class="w-full pt-10 text-center">
				<button class="bg-orange-600 hover:bg-orange-500 w-96" type="submit"
					>Register new OAuth application on GitLab</button
				>
			</div>

			<Explainer
				maxWidthClass="w-full"
				text="<span class='font-bold text-base'>Scopes required:</span> 	
	<br>- api (Access the authenticated user's API)
	<br>- read_repository (Allows read-only access to the repository)
	<br>- email (Allows read-only access to the user's primary email address using OpenID Connect)"
			/>
		</form>
		<form
			action={`/sources/${id}.json`}
			method="post"
			use:enhance={{
				result: async () => {
					setTimeout(async () => {
						loading = false;
						window.location.reload();
					}, 200);
				},
				pending: async () => {
					loading = true;
				}
			}}
			class="grid grid-flow-row gap-2 py-4 pt-10"
		>
			<div class="flex space-x-2 h-8 items-center">
				<div class="font-bold text-xl text-white">Configuration</div>
				<button
					type="submit"
					class:bg-orange-600={!loading}
					class:hover:bg-orange-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			</div>

			<div class="grid grid-cols-3 items-start">
				<label for="oauthId">OAuth ID</label>
				<div class="col-span-2">
					<input
						on:change={checkOauthId}
						bind:this={oauthIdEl}
						name="oauthId"
						id="oauthId"
						required
						bind:value={payload.oauthId}
					/>
					<Explainer
						text="The OAuth ID is the unique identifier of the GitLab application. <br>You can find it <span class='underline'>in the URL</span> of your GitLab OAuth Application."
					/>
				</div>
			</div>
			{#if payload.applicationType === 'group'}
				<div class="grid grid-cols-3 items-center">
					<label for="groupName">Group Name</label>
					<div class="col-span-2">
						<input name="groupName" id="groupName" required bind:value={payload.groupName} />
					</div>
				</div>
			{/if}
			<div class="grid grid-cols-3 items-center">
				<label for="appId">Application ID</label>
				<div class="col-span-2">
					<input name="appId" id="appId" required bind:value={payload.appId} />
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="appSecret">Secret</label>
				<div class="col-span-2">
					<input
						name="appSecret"
						id="appSecret"
						type="password"
						required
						bind:value={payload.appSecret}
					/>
				</div>
			</div>
		</form>
	{:else}
		<a href={`${source.htmlUrl}/oauth/applications/${source.gitlabApp.oauthId}`}
			><button>Check OAuth Application on GitLab</button></a
		>
	{/if}
</div>
