<script lang="ts">
	import { session } from '$app/stores';
	const teams = ['asd', 'asdf'];

</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Teams</div>
</div>

<div class="max-w-2xl mx-auto">
	<div class="px-4 sm:px-6">
		{#each teams as team}
			<div>{team}</div>
		{/each}
	</div>
</div>
