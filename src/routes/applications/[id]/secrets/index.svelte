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
	import { page } from '$app/stores';
	import { get } from '$lib/api';

	const { id } = $page.params;

	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets.json`);
		secrets = [...data.secrets];
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Secrets for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>
<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<table class="mx-auto">
		<thead class=" rounded-xl border-b border-coolgray-500">
			<tr>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-warmGray-400"
					>Name</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-warmGray-400"
					>Value</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-warmGray-400"
					>Need during buildtime?</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-warmGray-400"
				/>
			</tr>
		</thead>
		<tbody class="">
			{#each secrets as secret}
				{#key secret.id}
					<tr class="hover:bg-coolgray-200">
						<Secret
							name={secret.name}
							value={secret.value ? secret.value : 'ENCRYPTED'}
							isBuildSecret={secret.isBuildSecret}
							on:refresh={refreshSecrets}
						/>
					</tr>
				{/key}
			{/each}
			<tr>
				<Secret isNewSecret on:refresh={refreshSecrets} />
			</tr>
		</tbody>
	</table>
</div>
