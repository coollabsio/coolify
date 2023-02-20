<script>
	import { addToast } from '$lib/store';

	export let value = '';
	let isHttps = window.location.protocol === 'https:';

	function copyToClipboard() {
		if (isHttps && navigator.clipboard) {
			navigator.clipboard.writeText(value);
			addToast({
				message: 'Copied to clipboard.',
				type: 'success'
			});
		}
	}
</script>

<div class="w-full relative box">
	<p class="text-white  p-2">{value}</p>
	<div
		class="absolute top-0 right-0 flex justify-center items-center h-full cursor-pointer text-stone-600 mr-3"
	>
		{#if isHttps}
			<div on:click={copyToClipboard}>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<rect x="8" y="8" width="12" height="12" rx="2" />
					<path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" />
				</svg>
			</div>
		{/if}
	</div>
</div>

<style>
	.box {
		position: relative;
		border: 1px dashed #202020;
		border-radius: 5px;
		padding: 5px;
	}
</style>
