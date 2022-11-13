<script lang="ts">
  export let thing:any;
  export let kind:any;
  // Icons
  import DatabaseIcons from "../svg/databases/DatabaseIcons.svelte";
	import LocalDockerIcon from "../svg/servers/LocalDockerIcon.svelte";
	import GithubIcon from "../svg/sources/GithubIcon.svelte";
	import GitlabIcon from "../svg/sources/GitlabIcon.svelte";
  import ApplicationsIcons from '$lib/components/svg/applications/ApplicationIcons.svelte';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';

  import {getStatus} from '$lib/api/status';
	import Tooltip from "../Tooltip.svelte";

  // @TODO: try to always have "type" coming from the API;
  let type = function(){
    if(typeof(thing.type) != 'undefined' && thing.type != null) return thing.type;
    if(thing.type === null) return 'service';
    if(typeof(thing.remoteEngine) != 'undefined') return 'docker';
    return '?'
  }

  let lastStatus:any = 'stopped'
  async function instanceStatus(){
    lastStatus = await getStatus(thing)
    return `instance-status-${lastStatus}`;
  }
  let htmlId = `icon-instance-${thing.id}`;
</script>
{#await instanceStatus()}
  <div class="icon-holder">...</div>
{:then status}
  <div class="icon-holder {status}" id={htmlId}>
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
      <ApplicationsIcons application={thing} isAbsolute={false}/>
      <ServiceIcons type={thing.type} isAbsolute={false}/>
    {/if}
  </div>
  <Tooltip triggeredBy={`#${htmlId}`} placement="right">{lastStatus}</Tooltip>
{/await}