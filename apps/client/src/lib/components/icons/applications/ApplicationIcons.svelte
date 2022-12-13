<script lang="ts">
	import * as Icons from '$lib/components/icons/applications';
	export let application: any;
	export let isAbsolute = true;
</script>

{#if application.buildPack?.toLowerCase() === 'rust'}
	<Icons.Rust {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'node'}
	<Icons.Nodejs {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'react'}
	<Icons.React {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'svelte'}
	<Icons.Svelte {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'vuejs'}
	<Icons.Vuejs {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'php'}
	<Icons.Php {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'python'}
	<Icons.Python {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'static'}
	<Icons.Static {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'nestjs'}
	<Icons.Nestjs {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'nuxtjs'}
	<Icons.Nuxtjs {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'nextjs'}
	<Icons.Nextjs {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'gatsby'}
	<Icons.Gatsby {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'docker'}
	<Icons.Docker {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'astro'}
	<Icons.Astro {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'eleventy'}
	<Icons.Eleventy {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'deno'}
	<Icons.Deno {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'laravel'}
	<Icons.Laravel {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'heroku'}
	<Icons.Heroku {isAbsolute} />
{:else if application.buildPack?.toLowerCase() === 'compose'}
	<Icons.Compose {isAbsolute} />
{:else if application.simpleDockerfile}
	<Icons.Docker {isAbsolute} />
{/if}
