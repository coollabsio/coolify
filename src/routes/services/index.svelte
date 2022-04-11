<script lang="ts">
	import PlausibleAnalytics from '$lib/components/svg/services/PlausibleAnalytics.svelte';
	import NocoDb from '$lib/components/svg/services/NocoDB.svelte';
	import MinIo from '$lib/components/svg/services/MinIO.svelte';
	import VsCodeServer from '$lib/components/svg/services/VSCodeServer.svelte';
	import Wordpress from '$lib/components/svg/services/Wordpress.svelte';
	import VaultWarden from '$lib/components/svg/services/VaultWarden.svelte';
	import LanguageTool from '$lib/components/svg/services/LanguageTool.svelte';
	import { post } from '$lib/api';
	import { goto } from '$app/navigation';
	import N8n from '$lib/components/svg/services/N8n.svelte';
	import UptimeKuma from '$lib/components/svg/services/UptimeKuma.svelte';
	import Ghost from '$lib/components/svg/services/Ghost.svelte';
	import MeiliSearch from '$lib/components/svg/services/MeiliSearch.svelte';
	import { session } from '$app/stores';
	import { getDomain } from '$lib/components/common';

	export let services;
	async function newService() {
		const { id } = await post('/services/new', {});
		return await goto(`/services/${id}`, { replaceState: true });
	}
	const ownServices = services.filter((service) => {
		if (service.teams[0].id === $session.teamId) {
			return service;
		}
	});
	const otherServices = services.filter((service) => {
		if (service.teams[0].id !== $session.teamId) {
			return service;
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Services</div>
	<div on:click={newService} class="add-icon cursor-pointer bg-pink-600 hover:bg-pink-500">
		<svg
			class="w-6"
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
			><path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M12 6v6m0 0v6m0-6h6m-6 0H6"
			/></svg
		>
	</div>
</div>

<div class="flex flex-col flex-wrap justify-center">
	{#if !services || ownServices.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No services found</div>
		</div>
	{/if}
	{#if ownServices.length > 0 || otherServices.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownServices as service}
					<a href="/services/{service.id}" class="w-96 p-2 no-underline">
						<div class="box-selection group relative hover:bg-pink-600">
							{#if service.type === 'plausibleanalytics'}
								<PlausibleAnalytics isAbsolute />
							{:else if service.type === 'nocodb'}
								<NocoDb isAbsolute />
							{:else if service.type === 'minio'}
								<MinIo isAbsolute />
							{:else if service.type === 'vscodeserver'}
								<VsCodeServer isAbsolute />
							{:else if service.type === 'wordpress'}
								<Wordpress isAbsolute />
							{:else if service.type === 'vaultwarden'}
								<VaultWarden isAbsolute />
							{:else if service.type === 'languagetool'}
								<LanguageTool isAbsolute />
							{:else if service.type === 'n8n'}
								<N8n isAbsolute />
							{:else if service.type === 'uptimekuma'}
								<UptimeKuma isAbsolute />
							{:else if service.type === 'ghost'}
								<Ghost isAbsolute />
							{:else if service.type === 'meilisearch'}
								<MeiliSearch isAbsolute />
							{/if}
							<div class="truncate text-center text-xl font-bold">
								{service.name}
							</div>
							{#if $session.teamId === '0' && otherServices.length > 0}
								<div class="truncate text-center">{service.teams[0].name}</div>
							{/if}
							{#if service.fqdn}
								<div class="truncate text-center">{getDomain(service.fqdn) || ''}</div>
							{/if}
							{#if !service.type || !service.fqdn}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>
			{#if otherServices.length > 0 && $session.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Services</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherServices as service}
						<a href="/services/{service.id}" class="w-96 p-2 no-underline">
							<div class="box-selection group relative hover:bg-pink-600">
								{#if service.type === 'plausibleanalytics'}
									<PlausibleAnalytics isAbsolute />
								{:else if service.type === 'nocodb'}
									<NocoDb isAbsolute />
								{:else if service.type === 'minio'}
									<MinIo isAbsolute />
								{:else if service.type === 'vscodeserver'}
									<VsCodeServer isAbsolute />
								{:else if service.type === 'wordpress'}
									<Wordpress isAbsolute />
								{:else if service.type === 'vaultwarden'}
									<VaultWarden isAbsolute />
								{:else if service.type === 'languagetool'}
									<LanguageTool isAbsolute />
								{:else if service.type === 'n8n'}
									<N8n isAbsolute />
								{:else if service.type === 'uptimekuma'}
									<UptimeKuma isAbsolute />
								{:else if service.type === 'ghost'}
									<Ghost isAbsolute />
								{:else if service.type === 'meilisearch'}
									<MeiliSearch isAbsolute />
								{/if}
								<div class="truncate text-center text-xl font-bold">
									{service.name}
								</div>
								{#if $session.teamId === '0'}
									<div class="truncate text-center">{service.teams[0].name}</div>
								{/if}
								{#if service.fqdn}
									<div class="truncate text-center">{getDomain(service.fqdn) || ''}</div>
								{/if}
								{#if !service.type || !service.fqdn}
									<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{:else}
									<div class="text-center truncate">{service.type}</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
