<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		let endpoint = `/applications/${params.id}/previews.json`;
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
	export let containers;
	export let application;
	export let PRMRSecrets;
	export let applicationSecrets;
	import { getDomain } from '$lib/components/common';
	import Secret from '../secrets/_Secret.svelte';
	import { get } from '$lib/api';
	import { page } from '$app/stores';

	const { id } = $page.params;
	async function refreshSecrets() {
		const data = await get(`/applications/${id}/secrets.json`);
		PRMRSecrets = [...data.secrets];
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">
		Previews for <a href={application.fqdn} target="_blank">{getDomain(application.fqdn)}</a>
	</div>
</div>
<div>
	Preview secrets. They will overwrite application secrets for PR/MR deployments. Useful for
	creating staging environments for these deployments.
</div>
<div class="mx-auto max-w-6xl rounded-xl px-6 pt-4">
	<table class="mx-auto">
		<thead class=" rounded-xl border-b border-coolgray-500">
			<tr>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-white">Name</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-white"
					>Value</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-white"
					>Need during buildtime?</th
				>
				<th
					scope="col"
					class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-white"
				/>
			</tr>
		</thead>
		<tbody class="">
			{#each applicationSecrets as secret}
				{#key secret.id}
					<tr class="hover:bg-coolgray-200">
						<Secret
							PRMRSecret={PRMRSecrets.find((s) => s.name === secret.name)}
							isPRMRSecret
							name={secret.name}
							value={secret.value ? secret.value : 'ENCRYPTED'}
							isBuildSecret={secret.isBuildSecret}
							on:refresh={refreshSecrets}
						/>
					</tr>
				{/key}
			{/each}
			<!-- <tr>
				<Secret isPRMRSecret isNewSecret on:refresh={refreshSecrets} />
			</tr> -->
		</tbody>
	</table>
</div>
<div class="mx-auto max-w-4xl py-10">
	<div class="flex flex-wrap justify-center space-x-2">
		{#if containers.length > 0}
			{#each containers as container}
				<a href={container.fqdn} class="p-2 no-underline" target="_blank">
					<div class="box-selection text-center hover:border-transparent hover:bg-coolgray-200">
						<div class="truncate text-center text-xl font-bold">{getDomain(container.fqdn)}</div>
					</div>
				</a>
			{/each}
		{:else}
			<div class="flex-col">
				<div class="text-center font-bold text-xl">No previews available</div>
			</div>
		{/if}
	</div>
</div>
