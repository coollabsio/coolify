<script lang="ts">
	export let name = '';
	export let value = '';
	export let readonly = false;
	export let isNewSecret = false;

	import { page } from '$app/stores';
	import { del, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { addToast, appSession } from '$lib/store';
	import { createEventDispatcher } from 'svelte';

	const dispatch = createEventDispatcher();
	const { id } = $page.params;
	async function removeSecret() {
		try {
			await del(`/services/${id}/secrets`, { name });
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function saveSecret(isNew = false) {
		if (!name) return errorNotification({ message: 'Name is required.' });
		if (!value) return errorNotification({ message: 'Value is required.' });
		try {
			await post(`/services/${id}/secrets`, {
				name,
				value,
				isNew
			});
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
			}
			addToast({
				message: 'Secret saved.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<td>
	<input
		style="min-width: 350px !important;"
		id={isNewSecret ? 'secretName' : 'secretNameNew'}
		bind:value={name}
		required
		placeholder="EXAMPLE_VARIABLE"
		readonly={!isNewSecret || readonly}
		class="w-full"
		class:bg-coolblack={!isNewSecret}
		class:border={!isNewSecret}
		class:border-dashed={!isNewSecret}
		class:border-coolgray-300={!isNewSecret}
	/>
</td>
<td>
	<CopyPasswordField
		id={isNewSecret ? 'secretValue' : 'secretValueNew'}
		name={isNewSecret ? 'secretValue' : 'secretValueNew'}
		disabled={readonly}
		{readonly}
		isPasswordField={true}
		bind:value
		placeholder="J$#@UIO%HO#$U%H"
		inputStyle="min-width: 350px; !important"
	/>
</td>

{#if $appSession.isAdmin}
<td>
	{#if isNewSecret}
		<div class="flex items-center justify-center">
			<button class="btn btn-sm btn-primary" on:click={() => saveSecret(true)}>Add</button>
		</div>
	{:else if !readonly}
		<div class="flex flex-row justify-center space-x-2">
			<div class="flex items-center justify-center">
				<button class="btn btn-sm btn-primary" on:click={() => saveSecret(false)}>Set</button>
			</div>
			<div class="flex justify-center items-end">
				<button class="btn btn-sm bg-error" on:click={removeSecret}>Remove</button>
			</div>
		</div>
	{/if}
</td>
{/if}
