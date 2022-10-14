<script lang="ts">
	export let type: string;
	export let isAbsolute = true;
	import * as Icons from '$lib/components/svg/services';
	const name: any = type && type[0].toUpperCase() + type.substring(1).toLowerCase();
</script>

<svelte:component this={Icons[name]} {isAbsolute} />
