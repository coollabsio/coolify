<script lang="ts">
  import Tooltip from "../Tooltip.svelte";
  import {initials} from '$lib/common';
  export let teams:any;
  export let thing:any;
  let id = 'teams' + thing.id;
</script>

<span>
  🏢
  {#each teams as team}
    <a href={`/iam/teams/${team.id}`} {id} style="color: #99f8; text-decoration: none;">
			{initials(team.name)}
    </a>
    <Tooltip triggeredBy="#{id}" placement="right" color="bg-sky-500/50">{team.name}</Tooltip>
  {/each}
</span>