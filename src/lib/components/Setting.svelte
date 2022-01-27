<script lang="ts">
	import Explainer from '$lib/components/Explainer.svelte';

	export let setting;
	export let title;
	export let description;
	export let isPadding = true;
	export let disabled = false;
	export let disabledReason = '';
</script>

<li class="flex items-center py-4">
	<div class="flex flex-col" class:px-4={isPadding} class:pr-32={!isPadding}>
		<p class="text-base font-bold text-stone-100">{title}</p>
		<Explainer text={description} />
	</div>
	<!-- {#if disabled}
		<div class="flex" class:px-4={isPadding} class:pr-32={!isPadding}>
			<p class="text-xs font-bold text-stone-400">{disabledReason}</p>
		</div>
	{:else} -->
	<div
		type="button"
		data-tooltip={disabledReason && disabledReason}
		on:click
		aria-pressed="false"
		class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 mx-20"
		class:tooltip-bottom={disabled}
		class:opacity-50={disabled}
		class:bg-green-600={setting}
		class:bg-stone-700={!setting}
	>
		<span class="sr-only">Use setting</span>
		<span
			class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
			class:translate-x-5={setting}
			class:translate-x-0={!setting}
		>
			<span
				class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
				class:opacity-0={setting}
				class:opacity-100={!setting}
				aria-hidden="true"
			>
				<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
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
				class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
				aria-hidden="true"
				class:opacity-100={setting}
				class:opacity-0={!setting}
			>
				<svg class="bg-white h-3 w-3 text-green-600" fill="currentColor" viewBox="0 0 12 12">
					<path
						d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
					/>
				</svg>
			</span>
		</span>
	</div>
	<!-- {/if} -->
</li>
