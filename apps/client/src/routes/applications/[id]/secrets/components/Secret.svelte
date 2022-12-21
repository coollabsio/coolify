<script lang="ts">
	export let length = 0;
	export let index: number = 0;
	export let name = '';
	export let value = '';
	export let isBuildSecret = false;
	export let isNewSecret = false;

	import { page } from '$app/stores';
	import { errorNotification } from '$lib/common';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { addToast, trpc } from '$lib/store';
	import { createEventDispatcher } from 'svelte';

	const dispatch = createEventDispatcher();
	const { id } = $page.params;
	function cleanupState() {
		if (isNewSecret) {
			name = '';
			value = '';
			isBuildSecret = false;
		}
	}
	async function removeSecret() {
		try {
			await trpc.applications.deleteSecret.mutate({ id, name });
			cleanupState();
			addToast({
				message: 'Secret removed.',
				type: 'success'
			});
			dispatch('refresh');
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function addNewSecret() {
		try {
			if (!name.trim()) return errorNotification({ message: 'Name is required.' });
			if (!value.trim()) return errorNotification({ message: 'Value is required.' });
			await trpc.applications.newSecret.mutate({
				id,
				name: name.trim(),
				value: value.trim(),
				isBuildSecret
			});
			cleanupState();
			addToast({
				message: 'Secret added.',
				type: 'success'
			});
			dispatch('refresh');
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function updateSecret({
		changeIsBuildSecret = false
	}: { changeIsBuildSecret?: boolean } = {}) {
		if (changeIsBuildSecret) isBuildSecret = !isBuildSecret;
		if (isNewSecret) return;
		try {
			await trpc.applications.updateSecret.mutate({
				id,
				name: name.trim(),
				value: value.trim(),
				isBuildSecret,
				isPreview: false
			});
			addToast({
				message: 'Secret updated.',
				type: 'success'
			});
			dispatch('refresh');
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="w-full grid grid-cols-1 lg:grid-cols-4 gap-2 pb-2">
	<div class="flex flex-col">
		{#if (index === 0 && !isNewSecret) || length === 0}
			<label for="name" class="pb-2 uppercase font-bold">name</label>
		{/if}

		<input
			id={isNewSecret ? 'secretName' : 'secretNameNew'}
			bind:value={name}
			required
			placeholder="EXAMPLE_VARIABLE"
			readonly={!isNewSecret}
			class="w-full"
			class:bg-coolblack={!isNewSecret}
			class:border={!isNewSecret}
			class:border-dashed={!isNewSecret}
			class:border-coolgray-300={!isNewSecret}
			class:cursor-not-allowed={!isNewSecret}
		/>
	</div>
	<div class="flex flex-col">
		{#if (index === 0 && !isNewSecret) || length === 0}
			<label for="value" class="pb-2 uppercase font-bold">value</label>
		{/if}

		<CopyPasswordField
			id={isNewSecret ? 'secretValue' : 'secretValueNew'}
			name={isNewSecret ? 'secretValue' : 'secretValueNew'}
			isPasswordField={true}
			bind:value
			placeholder="J$#@UIO%HO#$U%H"
		/>
	</div>
	<div class="flex lg:flex-col flex-row justify-start items-center pt-3 lg:pt-0">
		{#if (index === 0 && !isNewSecret) || length === 0}
			<label for="name" class="pb-2 uppercase lg:block hidden font-bold"
				>Need during buildtime?</label
			>
		{/if}
		<label for="name" class="pb-2 uppercase lg:hidden block font-bold">Need during buildtime?</label
		>

		<div class="flex justify-center h-full items-center pt-0 lg:pt-0 pl-4 lg:pl-0">
			<button
				on:click={() => updateSecret({ changeIsBuildSecret: true })}
				aria-pressed="false"
				class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out "
				class:bg-green-600={isBuildSecret}
				class:bg-stone-700={!isBuildSecret}
			>
				<span class="sr-only">Is build secret?</span>
				<span
					class="pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 ease-in-out"
					class:translate-x-5={isBuildSecret}
					class:translate-x-0={!isBuildSecret}
				>
					<span
						class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-200 ease-in"
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
			</button>
		</div>
	</div>
	<div class="flex flex-row lg:flex-col lg:items-center items-start">
		{#if (index === 0 && !isNewSecret) || length === 0}
			<label for="name" class="pb-5 uppercase lg:block hidden font-bold" />
		{/if}

		<div class="flex justify-center h-full items-center pt-3">
			{#if isNewSecret}
				<div class="flex items-center justify-center">
					<button class="btn btn-sm btn-primary" on:click={addNewSecret}>Add</button>
				</div>
			{:else}
				<div class="flex flex-row justify-center space-x-2">
					<div class="flex items-center justify-center">
						<button class="btn btn-sm btn-primary" on:click={() => updateSecret()}>Set</button>
					</div>
					<div class="flex justify-center items-end">
						<button class="btn btn-sm btn-error" on:click={removeSecret}>Remove</button>
					</div>
				</div>
			{/if}
		</div>
	</div>
</div>
