<script lang="ts">
	export let application;
	import Rust from '$lib/components/svg/applications/Rust.svelte';
	import Nodejs from '$lib/components/svg/applications/Nodejs.svelte';
	import React from '$lib/components/svg/applications/React.svelte';
	import Svelte from '$lib/components/svg/applications/Svelte.svelte';
	import Vuejs from '$lib/components/svg/applications/Vuejs.svelte';
	import PHP from '$lib/components/svg/applications/PHP.svelte';
	import Python from '$lib/components/svg/applications/Python.svelte';
	import Static from '$lib/components/svg/applications/Static.svelte';
	import Nestjs from '$lib/components/svg/applications/Nestjs.svelte';
	import Nuxtjs from '$lib/components/svg/applications/Nuxtjs.svelte';
	import Nextjs from '$lib/components/svg/applications/Nextjs.svelte';
	import Gatsby from '$lib/components/svg/applications/Gatsby.svelte';
	import Docker from '$lib/components/svg/applications/Docker.svelte';
	
	const buildPack = application?.buildPack?.toLowerCase()
</script>

<a href="/applications/{application.id}" class="no-underline p-2">
	<div class="box-selection relative hover:bg-green-600">
		{#if buildPack === 'rust'}
			<Rust />
		{:else if buildPack === 'node'}
			<Nodejs />
		{:else if buildPack === 'react'}
			<React />
		{:else if buildPack === 'svelte'}
			<Svelte />
		{:else if buildPack === 'vuejs'}
			<Vuejs />
		{:else if buildPack === 'php'}
			<PHP />
		{:else if buildPack === 'python'}
			<Python />
		{:else if buildPack === 'static'}
			<Static />
		{:else if buildPack === 'nestjs'}
			<Nestjs />
		{:else if buildPack === 'nuxtjs'}
			<Nuxtjs />
		{:else if buildPack === 'nextjs'}
			<Nextjs />
		{:else if buildPack === 'gatsby'}
			<Gatsby />
		{:else if buildPack === 'docker'}
			<Docker />
		{/if}

		<div class="font-bold text-xl text-center truncate">{application.name}</div>
		{#if application.fqdn}
			<div class="text-center truncate">{application.fqdn}</div>
		{/if}
		{#if !application.gitSourceId || !application.destinationDockerId}
			<div class="font-bold text-center truncate">Configuration missing</div>
		{/if}
	</div>
</a>
