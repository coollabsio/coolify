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
	import { t } from '$lib/translations';
	import MeiliSearch from '$lib/components/svg/services/MeiliSearch.svelte';

	export let services;
	async function newService() {
		const { id } = await post('/services/new', {});
		return await goto(`/services/${id}`, { replaceState: true });
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.services')}</div>
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

<div class="flex flex-wrap justify-center">
	{#if !services || services.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('service.no_service')}</div>
		</div>
	{:else}
		{#each services as service}
			<a href="/services/{service.id}" class="no-underline p-2 w-96">
				<div class="box-selection relative hover:bg-pink-600 group">
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
					<div class="font-bold text-xl text-center truncate">
						{service.name}
					</div>
					{#if !service.type || !service.fqdn}
						<div class="font-bold text-center truncate text-red-500 group-hover:text-white">
							{$t('application.configuration.configuration_missing')}
						</div>
					{:else}
						<div class="text-center truncate">{service.type}</div>
					{/if}
				</div>
			</a>
		{/each}
	{/if}
</div>
