<script lang="ts">
	import Explainer from '$lib/components/Explainer.svelte';

	export let setting;
	export let title;
	export let description;
	export let isCenter = true;
	export let disabled = false;
	export let dataTooltip = null;
	export let loading = false;
</script>

<div class="flex items-center py-4 pr-8">
	<div class="flex w-96 flex-col">
		<div class="text-xs font-bold text-stone-100 md:text-base">{title}</div>
		<Explainer text={description} />
	</div>
</div>
<div
	class:tooltip={dataTooltip}
	class:text-center={isCenter}
	data-tooltip={dataTooltip}
	class="flex justify-center"
>
	<div
		type="button"
		on:click
		aria-pressed="false"
		class="relative mx-20 inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
		class:opacity-50={disabled || loading}
		class:bg-green-600={!loading && setting}
		class:bg-stone-700={!loading && !setting}
		class:bg-yellow-500={loading}
	>
		<span class="sr-only">Use setting</span>
		<span
			class="pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 ease-in-out"
			class:translate-x-5={setting}
			class:translate-x-0={!setting}
		>
			<span
				class=" absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-200 ease-in"
				class:opacity-0={setting}
				class:opacity-100={!setting}
				class:animate-spin={loading}
				aria-hidden="true"
			>
				<svg class="h-3 w-3 bg-white text-red-600" fill="none" viewBox="0 0 12 12">
					<path
						d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
					/>
				</svg>
			</span>
			<span
				class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-100 ease-out"
				aria-hidden="true"
				class:opacity-100={setting}
				class:opacity-0={!setting}
				class:animate-spin={loading}
			>
				<svg class="h-3 w-3 bg-white text-green-600" fill="currentColor" viewBox="0 0 12 12">
					<path
						d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
					/>
				</svg>
			</span>
		</span>
	</div>
</div>
