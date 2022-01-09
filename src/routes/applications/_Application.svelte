<script lang="ts">
	export let application;
</script>

<a href="/applications/{application.id}" class="no-underline p-2">
	<div
		class="box-selection"
		class:border-red-500={application.buildPack === 'static'}	
		class:border-green-500={application.buildPack === 'node'}
		class:border-sky-500={application.buildPack === 'docker'}
	>
		<div class="font-bold text-xl text-center truncate">{application.name}</div>
		{#if application.domain}
			<div class="text-center truncate">{application.domain}</div>
		{/if}
		{#if !application.gitSourceId || !application.destinationDockerId}
			<div class="font-bold text-center truncate text-red-500">Not configured</div>
		{/if}
	</div>
</a>
