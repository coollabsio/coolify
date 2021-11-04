<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		if (stuff?.source) {
			return {
				props: {
					source: stuff.source
				}
			};
		}
		const url = `/sources/${page.params.id}.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	export let source: Prisma.GitSource;
	import type Prisma from '@prisma/client';
	import { dashify } from '$lib/github';
	import { page } from '$app/stores';

	const { id } = $page.params;

	async function installRepositories(source) {
		window.location.assign(`https://github.com/apps/${source.githubApp.name}/installations/new`);
	}

	function newGithubApp(source) {
		const { organization, id, htmlUrl, type } = source;
		if (type === 'github') {
			let url = 'settings/apps/new';
			if (organization) url = `organizations/${organization}/settings/apps/new`;
			const host = dashify(window.location.host);
			const data = JSON.stringify({
				name: `coolify-${host}`,
				url: `https://${window.location.host}`,
				hook_attributes: {
					url: `https://${host}/webhooks/applications/deploy`
				},
				redirect_url: `http://${window.location.host}/webhooks/github`,
				callback_urls: [`https://${window.location.host}/login/github/app`],
				public: false,
				request_oauth_on_install: false,
				setup_url: `http://${window.location.host}/webhooks/github/install?gitSourceId=${id}`,
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
		}
	}
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl">
	<div class="tracking-tight">Git Source</div>
	<span class="px-1 arrow-right-applications">></span>
	<span class="pr-2">{source.name}</span>
</div>
<div class="flex justify-center space-x-2">
	{#if source.type === 'github' && !source.githubAppId}
		<button on:click={() => newGithubApp(source)}>Create new GitHub App</button>
	{/if}
	{#if source.githubAppId}
		{#if source.githubApp?.installationId}
			<button on:click={() => installRepositories(source)}
				>Update Repositories</button
			>
		{:else}
			<button on:click={() => installRepositories(source)}>Install Repositories</button>
		{/if}
	{/if}


</div>
