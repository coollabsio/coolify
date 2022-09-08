<script lang="ts">
	export let name = '';
	export let value = '';
	export let isBuildSecret = false;
	export let isNewSecret = false;
	export let isPRMRSecret = false;
	export let PRMRSecret: any = {};

	if (isPRMRSecret) value = PRMRSecret.value;

	import { page } from '$app/stores';
	import { del } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { addToast } from '$lib/store';
	import { t } from '$lib/translations';
	import { createEventDispatcher } from 'svelte';
	import { saveSecret } from './utils';

	const dispatch = createEventDispatcher();
	const { id } = $page.params;
	async function removeSecret() {
		try {
			await del(`/databases/${id}/secrets`, { name });
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
				isBuildSecret = false;
			}
			addToast({
				message: 'Secret removed.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function createSecret(isNew: any) {
		try {
			if (!name || !value) return;
			await saveSecret({
				isNew,
				name,
				value,
				isBuildSecret,
				isPRMRSecret,
				isNewSecret,
				databaseId: id
			});
			if (isNewSecret) {
				name = '';
				value = '';
				isBuildSecret = false;
				addToast({
					message: 'Secret added.',
					type: 'success'
				});
			} else {
				addToast({
				message: 'Secret updated.',
				type: 'success'
			});
			}
			dispatch('refresh');
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function setSecretValue() {
		if (!isPRMRSecret) {
			isBuildSecret = !isBuildSecret;
			if (!isNewSecret) {
				await saveSecret({
					isNew: isNewSecret,
					name,
					value,
					isBuildSecret,
					isPRMRSecret,
					isNewSecret,
					databaseId: id
				});
				addToast({
					message: 'Secret updated.',
					type: 'success'
				});
			}
		}
	}
</script>

<td>
	<input
		id={isNewSecret ? 'secretName' : 'secretNameNew'}
		bind:value={name}
		required
		placeholder="EXAMPLE_VARIABLE"
		readonly={!isNewSecret}
		class:bg-transparent={!isNewSecret}
		class:cursor-not-allowed={!isNewSecret}
	/>
</td>
<td>
	<CopyPasswordField
		id={isNewSecret ? 'secretValue' : 'secretValueNew'}
		name={isNewSecret ? 'secretValue' : 'secretValueNew'}
		isPasswordField={true}
		bind:value
		required
		placeholder="J$#@UIO%HO#$U%H"
	/>
</td>
<td class="text-center">
	<button
		on:click={setSecretValue}
		aria-pressed="false"
		class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
		class:bg-green-600={isBuildSecret}
		class:bg-stone-700={!isBuildSecret}
		class:opacity-50={isPRMRSecret}
		class:cursor-not-allowed={isPRMRSecret}
		class:cursor-pointer={!isPRMRSecret}
	>
		<span class="sr-only">Need during buildtime?</span>
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
	</button>
</td>
<td>
	{#if isNewSecret}
		<div class="flex items-center justify-center">
			<button class="btn bg-databases btn-sm" on:click={() => createSecret(true)}
				>{$t('forms.add')}</button
			>
		</div>
	{:else}
		<div class="flex flex-row justify-center space-x-2">
			<div class="flex items-center justify-center">
				<button class="btn bg-databases btn-sm" on:click={() => createSecret(false)}
					>{$t('forms.set')}</button
				>
			</div>
			{#if !isPRMRSecret}
				<div class="flex justify-center items-end">
					<button class="btn btn-sm bg-red-600 hover:bg-red-500" on:click={removeSecret}
						>{$t('forms.remove')}</button
					>
				</div>
			{/if}
		</div>
	{/if}
</td>
