<script lang="ts">
	export let length = 0;
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
			await del(`/applications/${id}/secrets`, { name });
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
			if (isNew) {
				if (!name || !value) {
					return addToast({
						message: 'Please fill in all fields.',
						type: 'error'
					});
				}
			}
			if (value === undefined && isPRMRSecret) {
				return;
			}
			if (value === '' && !isPRMRSecret) {
				throw new Error('Value is required.');
			}
			await saveSecret({
				isNew,
				name,
				value,
				isBuildSecret,
				isPRMRSecret,
				isNewSecret,
				applicationId: id
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
					applicationId: id
				});
				addToast({
					message: 'Secret updated.',
					type: 'success'
				});
			}
		}
	}
</script>

<div class="w-full font-bold grid grid-cols-1 lg:grid-cols-4 gap-2">
	<div class="flex flex-col">
		<label for="name" class="pb-2 uppercase">{length > 0 && !isNewSecret ? 'name' : ''}</label>
		{#if isNewSecret}
			<div class="lg:hidden block pt-10 pb-2">New Secret</div>
		{/if}
		<input
			id={isNewSecret ? 'secretName' : 'secretNameNew'}
			bind:value={name}
			required
			placeholder="EXAMPLE_VARIABLE"
			readonly={!isNewSecret}
			class="lg:w-64 w-full"
			class:bg-coolblack={!isNewSecret}
			class:border={!isNewSecret}
			class:border-dashed={!isNewSecret}
			class:border-coolgray-300={!isNewSecret}
			class:cursor-not-allowed={!isNewSecret}
		/>
	</div>
	<div class="flex flex-col">
		<label for="name" class="pb-2 uppercase">{length > 0 && !isNewSecret ? 'value' : ''}</label>
		<CopyPasswordField
			id={isNewSecret ? 'secretValue' : 'secretValueNew'}
			name={isNewSecret ? 'secretValue' : 'secretValueNew'}
			isPasswordField={true}
			bind:value
			placeholder="J$#@UIO%HO#$U%H"
		/>
	</div>
	<div class="flex lg:flex-col flex-row justify-start items-center pt-3 lg:pt-0">
		<label for="name" class="uppercase"
			>{length > 0 && !isNewSecret ? 'Need during buildtime?' : ''}</label
		>
		{#if isNewSecret}
			<label class="lg:hidden uppercase" for="name">Need during buildtime?</label>
		{/if}
		<div class="flex justify-center h-full items-center pt-0 lg:pt-3 pl-4 lg:pl-0">
			<button
				on:click={setSecretValue}
				aria-pressed="false"
				class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out "
				class:bg-green-600={isBuildSecret}
				class:bg-stone-700={!isBuildSecret}
				class:opacity-50={isPRMRSecret}
				class:cursor-not-allowed={isPRMRSecret}
				class:cursor-pointer={!isPRMRSecret}
			>
				<span class="sr-only">{$t('application.secrets.use_isbuildsecret')}</span>
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
		<label for="name" class="lg:block hidden uppercase">{length > 0 && !isNewSecret ? 'Actions' : ''}</label>
		<div class="flex justify-center h-full items-center pt-3">
			{#if isNewSecret}
				<div class="flex items-center justify-center">
					<button class="btn btn-sm btn-primary" on:click={() => createSecret(true)}
						>Save New Secret</button
					>
				</div>
			{:else}
				<div class="flex flex-row justify-center space-x-2">
					<div class="flex items-center justify-center">
						<button class="btn btn-sm btn-primary" on:click={() => createSecret(false)}
							>Set</button
						>
					</div>
					{#if !isPRMRSecret}
						<div class="flex justify-center items-end">
							<button class="btn btn-sm btn-error" on:click={removeSecret}
								>Remove</button
							>
						</div>
					{/if}
				</div>
			{/if}
		</div>
	</div>
</div>
