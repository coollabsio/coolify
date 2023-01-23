<script lang="ts">
	import ExternalLink from './ExternalLink.svelte';
	import Tooltip from './Tooltip.svelte';
	export let url = 'https://docs.coollabs.io';
	export let text: any = '';
	export let isExternal = false;
	let id =
		'cool-' +
		url
			.split('')
			.map((c) => c.charCodeAt(0).toString(16).padStart(2, '0'))
			.join('')
			.slice(-16);
</script>

<a
	{id}
	href={url}
	target="_blank noreferrer"
	class="flex no-underline inline-block cursor-pointer"
	class:icons={!text}
>
	<svg
		xmlns="http://www.w3.org/2000/svg"
		fill="none"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		class="w-6 h-6"
	>
		<path
			stroke-linecap="round"
			stroke-linejoin="round"
			d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"
		/>
	</svg>
	{text}
	{#if isExternal}
		<ExternalLink />
	{/if}
</a>
{#if !text}
	<Tooltip triggeredBy={`#${id}`}>See details in the documentation</Tooltip>
{/if}
