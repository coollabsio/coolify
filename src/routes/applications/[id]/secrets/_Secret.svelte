<script>
	export let name = '';
	export let value = '';
	export let isBuildSecret = false;
	export let isNewSecret = false;
	export let isPRMRSecret = false;
	export let PRMRSecret = {};

	if (isPRMRSecret) value = PRMRSecret.value;

	import { page } from '$app/stores';
	import { del, post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { createEventDispatcher } from 'svelte';

	const dispatch = createEventDispatcher();
	let nameEl;
	let valueEl;
	const { id } = $page.params;
	async function removeSecret() {
		try {
			await del(`/applications/${id}/secrets.json`, { name });
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
				isBuildSecret = false;
			}
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function saveSecret() {
		const nameValid = nameEl.checkValidity();
		const valueValid = valueEl.checkValidity();
		if (!nameValid) {
			return nameEl.reportValidity();
		}
		if (!valueValid) {
			return valueEl.reportValidity();
		}

		try {
			await post(`/applications/${id}/secrets.json`, { name, value, isBuildSecret, isPRMRSecret });
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
				isBuildSecret = false;
			}
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	function setSecretValue() {
		if (isNewSecret) {
			isBuildSecret = !isBuildSecret;
		}
	}
</script>

<td class="whitespace-nowrap px-6 py-2 text-sm font-medium text-white">
	<input
		id="secretName"
		bind:this={nameEl}
		bind:value={name}
		required
		placeholder="EXAMPLE_VARIABLE"
		class="-mx-2 w-64 border-2 border-transparent"
		readonly={!isNewSecret}
		class:bg-transparent={!isNewSecret}
		class:cursor-not-allowed={!isNewSecret}
	/>
</td>
<td class="whitespace-nowrap px-6 py-2 text-sm font-medium text-white">
	<input
		id="secretValue"
		bind:value
		bind:this={valueEl}
		required
		placeholder="J$#@UIO%HO#$U%H"
		class="-mx-2 w-64 border-2 border-transparent"
		class:bg-transparent={!isNewSecret && !isPRMRSecret}
		class:cursor-not-allowed={!isNewSecret && !isPRMRSecret}
		readonly={!isNewSecret && !isPRMRSecret}
	/>
</td>
<td class="whitespace-nowrap px-6 py-2 text-center text-sm font-medium text-white">
	<div
		type="button"
		on:click={setSecretValue}
		aria-pressed="false"
		class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
		class:bg-green-600={isBuildSecret}
		class:bg-stone-700={!isBuildSecret}
		class:opacity-50={!isNewSecret}
		class:cursor-not-allowed={!isNewSecret}
		class:cursor-pointer={isNewSecret}
	>
		<span class="sr-only">Use isBuildSecret</span>
		<span
			class="pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 ease-in-out"
			class:translate-x-5={isBuildSecret}
			class:translate-x-0={!isBuildSecret}
		>
			<span
				class=" absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-200 ease-in"
				class:opacity-0={isBuildSecret}
				class:opacity-100={!isBuildSecret}
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
				class:opacity-100={isBuildSecret}
				class:opacity-0={!isBuildSecret}
			>
				<svg class="h-3 w-3 bg-white text-green-600" fill="currentColor" viewBox="0 0 12 12">
					<path
						d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
					/>
				</svg>
			</span>
		</span>
	</div>
</td>
<td class="whitespace-nowrap px-6 py-2 text-sm font-medium text-white">
	{#if isNewSecret}
		<div class="flex items-center justify-center">
			<button class="w-24 bg-green-600 hover:bg-green-500" on:click={saveSecret}>Add</button>
		</div>
	{:else if isPRMRSecret}
		<div class="flex items-center justify-center">
			<button class="w-24 bg-green-600 hover:bg-green-500" on:click={saveSecret}>Set</button>
		</div>
	{:else}
		<div class="flex justify-center items-end">
			<button class="w-24 bg-red-600 hover:bg-red-500" on:click={removeSecret}>Remove</button>
		</div>
	{/if}
</td>
