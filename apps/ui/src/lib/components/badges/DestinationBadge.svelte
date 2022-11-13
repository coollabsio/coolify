<script>
	import Tooltip from "../Tooltip.svelte";

  export let name;
  export let thingId;
  let id = 'destination' + thingId;
  let initials = (str) => (str||'').split(' ').map( (wrd) => wrd[0]).join('')
</script>

{#if (name||'').length > 0}
  <span class="badge rounded uppercase text-xs	" id={id}>
    {initials(name)}
  </span>
  <Tooltip triggeredBy="#{id}" placement="right">{name}</Tooltip>
{/if}