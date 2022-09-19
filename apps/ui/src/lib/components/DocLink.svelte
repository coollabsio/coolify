<script lang="ts">
	import Tooltip from './Tooltip.svelte';
	export let url = 'https://docs.coollabs.io';
	let id =
		'cool-' +
		url
			.split('')
			.map((c) => c.charCodeAt(0).toString(16).padStart(2, '0'))
			.join('')
			.slice(-16);
</script>

<a {id} href={url} target="_blank" class="icons inline-block cursor-pointer text-xs mx-2">
	<svg
		xmlns="http://www.w3.org/2000/svg"
		class="w-6 h-6"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<path
			d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-11a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1m3 0v18"
		/>
		<line x1="13" y1="8" x2="15" y2="8" />
		<line x1="13" y1="12" x2="15" y2="12" />
	</svg>
</a>
<Tooltip triggeredBy={`#${id}`}>See details in the documentation</Tooltip>
