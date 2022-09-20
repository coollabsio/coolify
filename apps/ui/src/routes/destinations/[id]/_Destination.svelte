<script lang="ts">
	export let destination: any;
	export let settings: any;
	import LocalDocker from './_LocalDocker.svelte';
	import RemoteDocker from './_RemoteDocker.svelte';
</script>

<div class="mx-auto max-w-6xl px-6">
	{#if destination.remoteEngine}
		<RemoteDocker bind:destination {settings} />
	{:else}
		<LocalDocker bind:destination {settings} />
	{/if}
</div>
