<script lang="ts">
	import DestinationBadge from "../badges/DestinationBadge.svelte";
import DatabaseIcons from "../svg/databases/DatabaseIcons.svelte";
	import LocalDockerIcon from "../svg/servers/LocalDockerIcon.svelte";
	import GithubIcon from "../svg/sources/GithubIcon.svelte";
	import GitlabIcon from "../svg/sources/GitlabIcon.svelte";

  export let thing:any;
  export let kind:any; // Receives: app (application or service), server, database, source
  export let url:any;
  
  // @TODO: try to always have "type" coming from the API;
  let type = function(){
    if(typeof(thing.type) != 'undefined' && thing.type != null) return thing.type;
    if(thing.type === null) return 'service';
    if(typeof(thing.remoteEngine) != 'undefined') return 'docker';
    return '?'
  }
  
  // @TODO: try to always use "title" or "name" for resources simplification;
  let name = function(){
    if(typeof(thing.title) != 'undefined') return thing.title;
    return thing.name;
  }
  let instanceStatus = function(){
    'instance-status-on'
    'instance-status-off'
    'instance-status-degraded'
  }
  
</script>
<div class="card flex flex-row flex-1">
  <div class="w-14">
    <div class="icon-holder {instanceStatus()}">
      {#if kind == 'database'}
        <DatabaseIcons type={thing.type}/>
      {:else if kind == 'source'}
        {#if type() === 'gitlab'}
          <GitlabIcon />
        {:else if type() === 'github'}
          <GithubIcon />
        {/if}
      {:else if kind == 'server'}
        <LocalDockerIcon/>
      {:else if kind == 'app'}
        <LocalDockerIcon/>
      {/if}
    </div>
  </div>
  <div class="m-0 grow">
    <a href={url} class="no-underline">
      {name()} 
      <br/>
      <DestinationBadge name={thing.destinationDocker?.name} thingId={thing.id}/>
      <a href={thing.fqdn} target='_blank' style="color: #777;">{thing.fqdn || ''}</a>
    </a>
  </div>
</div>