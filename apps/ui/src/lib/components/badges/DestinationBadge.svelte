<script>
	import Tooltip from '../Tooltip.svelte';
	import { initials } from '$lib/common';
	export let name;
	export let thingId;
	let id = 'destination' + thingId;
</script>

{#if (name || '').length > 0}
	<span class="badge rounded uppercase text-xs	" {id}>
		{initials(name)}
	</span>
	<Tooltip triggeredBy="#{id}" placement="right">{name}</Tooltip>
{/if}
