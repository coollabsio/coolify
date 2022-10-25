<script lang="ts">
	import DocLink from '$lib/components/DocLink.svelte';
	export let service: any;
	export let linkToDocs: boolean = false;
	import * as Icons from '$lib/components/svg/services';
	const name: any = service.type && service.type[0].toUpperCase() + service.type.substring(1);

	const links: any = {
		plausible: 'https://plausible.io/doc/',
		nocodb: 'https://docs.nocodb.com',
		minio: 'https://min.io/docs/minio',
		vscodeserver: 'https://coder.com/docs/coder-oss/latest',
		wordpress: 'https://wordpress.org/',
		vaultwarden: 'https://github.com/dani-garcia/vaultwarden',
		languagetool: 'https://languagetool.org/dev',
		n8n: 'https://docs.n8n.io',
		uptimekuma: 'https://github.com/louislam/uptime-kuma',
		ghost: 'https://ghost.org/resources/',
		umami: 'https://umami.is/docs/getting-started',
		hasura: 'https://hasura.io/docs/latest/index/',
		fider: 'https://fider.io/docs',
		appwrite: 'https://appwrite.io/docs',
		moodle: 'https://docs.moodle.org/400/en/Main_page',
		glitchtip: 'https://glitchtip.com/documentation',
		searxng: 'https://searxng.org',
		weblate: 'https://docs.weblate.org/en/latest/',
		grafana: 'https://github.com/grafana/grafana',
		trilium: 'https://github.com/zadam/trilium'
	};
</script>

{#if linkToDocs}
	<DocLink url={links[service.type]} text={`Documentation`} isExternal={true} />
{:else}
	<svelte:component this={Icons[name]} />
{/if}

