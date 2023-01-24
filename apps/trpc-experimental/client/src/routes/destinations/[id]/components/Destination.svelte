<script lang="ts">
	export let destination: any;
	export let settings: any;
	import LocalDocker from './LocalDocker.svelte';
	import RemoteDocker from './RemoteDocker.svelte';
</script>

<div class="mx-auto max-w-6xl px-6">
	{#if destination.remoteEngine}
		<RemoteDocker bind:destination {settings} />
	{:else}
		<LocalDocker bind:destination {settings} />
	{/if}
</div>
