<script lang="ts">
	import DocLink from '$lib/components/DocLink.svelte';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';
	export let service: any;
	export let template: any;
	export let linkToDocs: boolean = false;
	const name: any = service.type && service.type[0].toUpperCase() + service.type.substring(1);
</script>

{#if linkToDocs}
	<DocLink url={template[service.id]?.documentation || 'https://docs.coollabs.io'} text={`Documentation`} isExternal={true} />
{:else}
	<ServiceIcons type={service.type} />
{/if}
