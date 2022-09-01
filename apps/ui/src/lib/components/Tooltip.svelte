<script lang="ts">
	import { Tooltip } from 'flowbite-svelte';
	export let placement = 'bottom';
	export let color = 'bg-coollabs text-left';
	export let triggeredBy = '#tooltip-default';
</script>

<Tooltip {triggeredBy} {placement} arrow={false} {color} style="custom"><slot /></Tooltip>
