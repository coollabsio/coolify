<script lang="ts">
	import Tooltip from '../Tooltip.svelte';
	import { initials } from '$lib/common';
	export let teams: any;
	export let thing: any;
	let id = 'teams' + thing.id;
</script>

<span>
	{#each teams as team}
		<a href={`/iam/teams/${team.id}`} {id} class="no-underline">
			Team: {initials(team.name)}
		</a>
		<Tooltip triggeredBy="#{id}" placement="right" color="bg-destinations">{team.name}</Tooltip>
	{/each}
</span>
