<script lang="ts">
	export let title: string = '';
	export let buttonText: string = '';
	export let isBeta: boolean = false;
	export let isModal: boolean = false;

	import Beta from '$lib/components/Beta.svelte';
</script>

<div class="flex gap-2 border-b border-coolgray-500 pb-3 mb-3">
	<h2 class={`title mr-2${isBeta ? ' flex items-center gap-2' : ''}`}>
		<slot name="title">
			{#if isBeta}
				{title} <Beta />
			{:else}
				{title}
			{/if}
		</slot>
	</h2>

	<slot>
		{#if buttonText && isModal}
			<button type="button" class="btn btn-sm btn-primary">
				<!-- svelte-ignore a11y-click-events-have-key-events -->
				<label for="my-modal" class="cursor-pointer" on:click>{buttonText}</label>
			</button>
		{:else if buttonText}
			<button type="button" class="btn btn-sm btn-primary" on:click>
				{buttonText}
			</button>
		{/if}
	</slot>
</div>
