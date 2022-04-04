<script>
	export let service;
	import Ghost from './svg/services/Ghost.svelte';
	import LanguageTool from './svg/services/LanguageTool.svelte';
	import MinIo from './svg/services/MinIO.svelte';
	import N8n from './svg/services/N8n.svelte';
	import NocoDb from './svg/services/NocoDB.svelte';
	import PlausibleAnalytics from './svg/services/PlausibleAnalytics.svelte';
	import UptimeKuma from './svg/services/UptimeKuma.svelte';
	import VaultWarden from './svg/services/VaultWarden.svelte';
	import VsCodeServer from './svg/services/VSCodeServer.svelte';
	import Wordpress from './svg/services/Wordpress.svelte';
</script>

{#if service.type === 'plausibleanalytics'}
	<a href="https://plausible.io" target="_blank">
		<PlausibleAnalytics />
	</a>
{:else if service.type === 'nocodb'}
	<a href="https://nocodb.com" target="_blank">
		<NocoDb />
	</a>
{:else if service.type === 'minio'}
	<a href="https://min.io" target="_blank">
		<MinIo />
	</a>
{:else if service.type === 'vscodeserver'}
	<a href="https://coder.com" target="_blank">
		<VsCodeServer />
	</a>
{:else if service.type === 'wordpress'}
	<a href="https://wordpress.org" target="_blank">
		<Wordpress />
	</a>
{:else if service.type === 'vaultwarden'}
	<a href="https://github.com/dani-garcia/vaultwarden" target="_blank">
		<VaultWarden />
	</a>
{:else if service.type === 'languagetool'}
	<a href="https://languagetool.org/dev" target="_blank">
		<LanguageTool />
	</a>
{:else if service.type === 'n8n'}
	<a href="https://n8n.io" target="_blank">
		<N8n />
	</a>
{:else if service.type === 'uptimekuma'}
	<a href="https://github.com/louislam/uptime-kuma" target="_blank">
		<UptimeKuma />
	</a>
{:else if service.type === 'ghost'}
	<a href="https://ghost.org" target="_blank">
		<Ghost />
	</a>
{/if}
