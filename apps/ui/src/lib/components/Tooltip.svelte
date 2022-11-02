<script lang="ts">
	import { Tooltip } from 'flowbite-svelte';
	export let placement = 'bottom';
	export let color = 'bg-coollabs font-thin text-left border-none p-4';
	export let triggeredBy = '#tooltip-default';
</script>

<Tooltip {triggeredBy} {placement} arrow={false} defaultClass={color} style="custom"><slot /></Tooltip>
