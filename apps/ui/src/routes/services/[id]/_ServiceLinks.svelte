<script lang="ts">
	import DocLink from '$lib/components/DocLink.svelte';
	export let service: any;
	export let template: any;
	export let linkToDocs: boolean = false;
	import * as Icons from '$lib/components/svg/services';
	const name: any = service.type && service.type[0].toUpperCase() + service.type.substring(1);
</script>

{#if linkToDocs}
	<DocLink url={template[service.id].documentation} text={`Documentation`} isExternal={true} />
{:else}
	<svelte:component this={Icons[name]} />
{/if}

