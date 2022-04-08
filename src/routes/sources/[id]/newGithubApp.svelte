<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { onMount } from 'svelte';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.source) {
			return {
				props: {
					source: stuff.source,
					settings: stuff.settings
				}
			};
		}
		const url = `/sources/${params.id}.json`;
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

<script>
	import { dev } from '$app/env';
	import { getDomain, dashify } from '$lib/components/common';

	export let source;
	export let settings;
	onMount(() => {
		const { organization, id, htmlUrl } = source;
		console.log(source);
		const { fqdn } = settings;
		const host = dev
			? 'http://localhost:3000'
			: fqdn
			? fqdn
			: `http://${window.location.host}` || '';
		const domain = getDomain(fqdn);

		let url = 'settings/apps/new';
		if (organization) url = `organizations/${organization}/settings/apps/new`;
		const name = dashify(domain) || 'app';
		const data = JSON.stringify({
			name: `coolify-${name}`,
			url: host,
			hook_attributes: {
				url: dev
					? 'https://webhook.site/0e5beb2c-4e9b-40e2-a89e-32295e570c21/events'
					: `${host}/webhooks/github/events`
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
	});
</script>

<div class="flex h-screen items-center justify-center text-3xl font-bold">
	Redirecting to Github...
</div>
