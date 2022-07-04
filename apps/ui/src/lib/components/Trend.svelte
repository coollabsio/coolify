<script lang="ts">
	export let trend: string;
</script>

{#if trend === 'up'}
	<span class="-mt-1 inline-flex px-2 text-green-500">
		<svg
			xmlns="http://www.w3.org/2000/svg"
			class="h-8 w-8"
			viewBox="0 0 24 24"
			stroke-width="1.5"
			stroke="currentColor"
			fill="none"
			stroke-linecap="round"
			stroke-linejoin="round"
		>
			<path stroke="none" d="M0 0h24v24H0z" fill="none" />
			<line x1="17" y1="7" x2="7" y2="17" />
			<polyline points="8 7 17 7 17 16" />
		</svg></span
	>
{:else if trend === 'down'}
	<span class="text-red-500 px-2 inline-flex -mt-1">
		<svg
			xmlns="http://www.w3.org/2000/svg"
			class="w-8 h-8"
			viewBox="0 0 24 24"
			stroke-width="1.5"
			stroke="currentColor"
			fill="none"
			stroke-linecap="round"
			stroke-linejoin="round"
		>
			<path stroke="none" d="M0 0h24v24H0z" fill="none" />
			<line x1="7" y1="7" x2="17" y2="17" />
			<polyline points="17 8 17 17 8 17" />
		</svg>
	</span>
{/if}
