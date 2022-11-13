<script lang="ts">
	import DestinationBadge from "../badges/DestinationBadge.svelte";
  import PublicBadge from "../badges/PublicBadge.svelte";
  
	import InstanceIcon from "./InstanceIcon.svelte";

  export let thing:any;
  export let kind:any; // Receives: app (application or service), server, database, source
  export let url:any;

  // @TODO: try to always use "title" or "name" for resources simplification;
  let name = function(){
    if(typeof(thing.title) != 'undefined') return thing.title;
    return thing.name;
  }
  
</script>
<div class="card flex flex-row flex-1">
  <div class="w-14">
    <InstanceIcon {thing} {kind} />
  </div>
  <div class="m-0 grow">
    <a href={url} class="no-underline">
      {name()} 
      <br/>
      <DestinationBadge name={thing.destinationDocker?.name} thingId={thing.id}/>
      <a href={thing.fqdn} target='_blank' style="color: #777;">{thing.fqdn || ''}</a>
    </a>
  </div>
  {#if thing.settings?.isPublic}
    <div class="w-8">
      <PublicBadge/>
    </div>
  {/if}
</div>