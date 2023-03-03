<script lang="ts">
	import Healthy from 'carbon-icons-svelte/lib/CheckmarkOutline.svelte';
	import Degraded from 'carbon-icons-svelte/lib/WarningAlt.svelte';
	import ErrorIcon from 'carbon-icons-svelte/lib/Error.svelte';
	import Stopped from 'carbon-icons-svelte/lib/PauseOutline.svelte';
	import InProgress from 'carbon-icons-svelte/lib/InProgress.svelte';
	import Rotate from 'carbon-icons-svelte/lib/Rotate.svelte';

	export let status: 'stopped' | 'error' | 'running' | 'degraded' | 'loading' | 'building' | 'healthy';

	// for screen-reader support
	$: title = `Service status is ${status}`
</script>

<span class="indicator-item">
	<span class="relative">
		{#if status === 'loading'}
			<Rotate title={title} class="w-6 h-6 text-gray-300 animate-spin motion-reduce:animate-none" style={"animation-direction: reverse !important;"}/>
		{:else if status === 'running' || status === 'healthy'}
			<Healthy title={title} class="w-6 h-6 text-success" />
		{:else if status === 'building'}
			<InProgress title={title} class="w-6 h-6 text-blue-400" />
		{:else if status === 'stopped'}
			<Stopped title={title} class="w-6 h-6 text-gray-400" />
		{:else if status === 'degraded'}
			<span
				class="motion-reduce:hidden w-4 h-4 m-1 absolute rounded-full bg-orange-400 animate-ping opacity-25"
			/>
			<Degraded title={title} class="w-6 h-6 pb-0.5 relative rounded-lg text-orange-400" />
		{:else}
			<span
				class="motion-reduce:hidden w-4 h-4 m-1 absolute rounded-full bg-error animate-ping opacity-25"
			/>
			<ErrorIcon title={title} class="w-6 h-6 relative rounded-lg text-error" />
		{/if}
	</span>
</span>
