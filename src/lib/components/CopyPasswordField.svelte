<script>
	import { browser } from '$app/env';
	import { toast } from '@zerodevx/svelte-toast';
	let showPassword = false;

	export let value;
	export let disabled = false;
	export let isPasswordField = false;
	export let readonly = false;
	export let textarea = false;
	export let required = false;
	export let pattern = null;
	export let id;
	export let name;
	export let placeholder = '';

	let disabledClass = 'bg-coolback disabled:bg-coolblack';
	let isHttps = browser && window.location.protocol === 'https:';

	function copyToClipboard() {
		if (isHttps && navigator.clipboard) {
			navigator.clipboard.writeText(value);
			toast.push('Copied to clipboard.');
		}
	}
</script>

<div class="relative">
	{#if !isPasswordField || showPassword}
		{#if textarea}
			<textarea
				rows="5"
				class={disabledClass}
				class:pr-10={true}
				class:pr-20={value && isHttps}
				{placeholder}
				type="text"
				{id}
				{pattern}
				{required}
				{readonly}
				{disabled}
				{name}>{value}</textarea
			>
		{:else}
			<input
				class={disabledClass}
				type="text"
				class:pr-10={true}
				class:pr-20={value && isHttps}
				{id}
				{name}
				{required}
				{pattern}
				{readonly}
				bind:value
				{disabled}
				{placeholder}
			/>
		{/if}
	{:else}
		<input
			class={disabledClass}
			class:pr-10={true}
			class:pr-20={value && isHttps}
			type="password"
			{id}
			{name}
			{readonly}
			{pattern}
			{required}
			bind:value
			{disabled}
			{placeholder}
		/>
	{/if}

	<div class="absolute top-0 right-0 m-3  cursor-pointer text-stone-600 hover:text-white">
		<div class="flex space-x-2">
			{#if isPasswordField}
				<div on:click={() => (showPassword = !showPassword)}>
					{#if showPassword}
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-6 w-6"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
							/>
						</svg>
					{:else}
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-6 w-6"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
							/>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
							/>
						</svg>
					{/if}
				</div>
			{/if}
			{#if value && isHttps}
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
</div>
