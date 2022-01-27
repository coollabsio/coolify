<script lang="ts">
	import { dev } from '$app/env';

	import { dashify } from '$lib/github';

	export let source;

	async function installRepositories(source) {
		const { htmlUrl } = source;
		const left = screen.width / 2 - 1020 / 2;
		const top = screen.height / 2 - 618 / 2;
		const newWindow = open(
			`${htmlUrl}/apps/${source.githubApp.name}/installations/new`,
			'GitHub',
			'resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=' +
				top +
				', left=' +
				left +
				', toolbar=0, menubar=0, status=0'
		);
		const timer = setInterval(() => {
			if (newWindow?.closed) {
				clearInterval(timer);
				window.location.reload();
			}
		}, 100);
	}

	function newGithubApp(source) {
		const { organization, id, htmlUrl, type } = source;
		const protocol = 'http';

		if (type === 'github') {
			let url = 'settings/apps/new';
			if (organization) url = `organizations/${organization}/settings/apps/new`;
			const host = dashify(window.location.host);
			const data = JSON.stringify({
				name: `coolify-${host}`,
				url: `${protocol}://${window.location.host}`,
				hook_attributes: {
					url: dev
						? 'https://webhook.site/0e5beb2c-4e9b-40e2-a89e-32295e570c21/events'
						: `${protocol}://${window.location.host}/webhooks/github/events`
				},
				redirect_url: `${protocol}://${window.location.host}/webhooks/github`,
				callback_urls: [`${protocol}://${window.location.host}/login/github/app`],
				public: false,
				request_oauth_on_install: false,
				setup_url: `${protocol}://${window.location.host}/webhooks/github/install?gitSourceId=${id}`,
				setup_on_update: true,
				default_permissions: {
					contents: 'read',
					metadata: 'read',
					pull_requests: 'read',
					emails: 'read'
				},
				default_events: ['pull_request', 'push']
			});
			console.log(data)
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

{#if !source.githubAppId}
	<button on:click={() => newGithubApp(source)}>Create new GitHub App</button>
{:else if source.githubApp?.installationId}
	<button on:click={() => installRepositories(source)}>Update Repositories</button>
{:else}
	<button on:click={() => installRepositories(source)}>Install Repositories</button>
{/if}
