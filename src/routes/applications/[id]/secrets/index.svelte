<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/applications/${params.id}/secrets.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					application: stuff.application,
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	export let secrets;
	export let application;
	import Secret from './_Secret.svelte';
	import { getDomain } from '$lib/components/common';
</script>

<div class="font-bold flex space-x-1 py-6 px-6">
	<div class="text-2xl tracking-tight mr-4">
		Secrets for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>
<div class="max-w-4xl mx-auto px-6">
	<div class="flex-col justify-start space-y-1">
		{#each secrets as secret}
			<Secret name={secret.name} value={secret.value} isBuildSecret={secret.isBuildSecret} />
		{/each}
		<Secret isNewSecret />
	</div>
</div>
