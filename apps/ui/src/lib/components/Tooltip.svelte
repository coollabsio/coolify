<script lang="ts">
	import { Tooltip } from 'flowbite-svelte';
	export let placement = 'bottom';
	export let color = 'bg-coollabs';
	export let triggeredBy = '#tooltip-default';
</script>

<Tooltip {triggeredBy} {placement} arrow={false} defaultClass={color + ' font-thin text-xs text-left border-none p-2'} style="custom"
	><slot /></Tooltip
>
