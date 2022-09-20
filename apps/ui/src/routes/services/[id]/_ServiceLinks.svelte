<script lang="ts">
	export let service: any;
	import * as Icons from '$lib/components/svg/services';
</script>

{#if service.type === 'plausibleanalytics'}
	<a href="https://plausible.io" target="_blank">
		<Icons.PlausibleAnalytics />
	</a>
{:else if service.type === 'nocodb'}
	<a href="https://nocodb.com" target="_blank">
		<Icons.NocoDb />
	</a>
{:else if service.type === 'minio'}
	<a href="https://min.io" target="_blank">
		<Icons.MinIo />
	</a>
{:else if service.type === 'vscodeserver'}
	<a href="https://coder.com" target="_blank">
		<Icons.VsCodeServer />
	</a>
{:else if service.type === 'wordpress'}
	<a href="https://wordpress.org" target="_blank">
		<Icons.Wordpress />
	</a>
{:else if service.type === 'vaultwarden'}
	<a href="https://github.com/dani-garcia/vaultwarden" target="_blank">
		<Icons.VaultWarden />
	</a>
{:else if service.type === 'languagetool'}
	<a href="https://languagetool.org/dev" target="_blank">
		<Icons.LanguageTool />
	</a>
{:else if service.type === 'n8n'}
	<a href="https://n8n.io" target="_blank">
		<Icons.N8n />
	</a>
{:else if service.type === 'uptimekuma'}
	<a href="https://github.com/louislam/uptime-kuma" target="_blank">
		<Icons.UptimeKuma />
	</a>
{:else if service.type === 'ghost'}
	<a href="https://ghost.org" target="_blank">
		<Icons.Ghost />
	</a>
{:else if service.type === 'umami'}
	<a href="https://umami.is" target="_blank">
		<Icons.Umami />
	</a>
{:else if service.type === 'hasura'}
	<a href="https://hasura.io" target="_blank">
		<Icons.Hasura />
	</a>
{:else if service.type === 'fider'}
	<a href="https://fider.io" target="_blank">
		<Icons.Fider />
	</a>
{:else if service.type === 'appwrite'}
	<a href="https://appwrite.io" target="_blank">
		<Icons.Appwrite />
	</a>
{:else if service.type === 'moodle'}
	<a href="https://moodle.org" target="_blank">
		<Icons.Moodle />
	</a>
{:else if service.type === 'glitchTip'}
	<a href="https://glitchtip.com" target="_blank">
		<Icons.GlitchTip />
	</a>
{:else if service.type === 'searxng'}
	<a href="https://searxng.org" target="_blank">
		<Icons.Searxng />
	</a>
{:else if service.type === 'weblate'}
	<a href="https://weblate.org" target="_blank">
		<Icons.Weblate />
	</a>
{:else if service.type === 'trilium'}
	<a href="https://github.com/zadam/trilium" target="_blank">
		<Icons.Trilium />
	</a>
{/if}
