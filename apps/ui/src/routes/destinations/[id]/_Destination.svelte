
<script lang="ts">
	export let destination: any;
	export let settings: any;
	export let state: any;
	import LocalDocker from './_LocalDocker.svelte';
	import RemoteDocker from './_RemoteDocker.svelte';
</script>

<div class="flex h-20 items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
		</div>
		<span class="text-xs">{destination.name}</span>
	</div>
</div>

<div class="mx-auto max-w-6xl px-6">
	{#if destination.remoteEngine}
		<RemoteDocker bind:destination {settings} {state} />
	{:else}
		<LocalDocker bind:destination {settings} {state} />
	{/if}
</div>