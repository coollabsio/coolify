<script>
	export let name = '';
	export let value = '';
	export let isNewSecret = false;

	import { page } from '$app/stores';
	import { del, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { errorNotification } from '$lib/form';
	import { toast } from '@zerodevx/svelte-toast';
	import { createEventDispatcher } from 'svelte';

	const dispatch = createEventDispatcher();
	const { id } = $page.params;
	async function removeSecret() {
		try {
			await del(`/services/${id}/secrets.json`, { name });
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
			}
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function saveSecret(isNew = false) {
		if (!name) return errorNotification('Name is required.');
		if (!value) return errorNotification('Value is required.');
		try {
			await post(`/services/${id}/secrets.json`, {
				name,
				value,

				isNew
			});
			dispatch('refresh');
			if (isNewSecret) {
				name = '';
				value = '';
			}
			toast.push('Secret saved.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<td>
	<input
		id={isNewSecret ? 'secretName' : 'secretNameNew'}
		bind:value={name}
		required
		placeholder="EXAMPLE_VARIABLE"
		class=" border border-dashed border-coolgray-300"
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

<td>
	{#if isNewSecret}
		<div class="flex items-center justify-center">
			<button class="bg-green-600 hover:bg-green-500" on:click={() => saveSecret(true)}>Add</button>
		</div>
	{:else}
		<div class="flex flex-row justify-center space-x-2">
			<div class="flex items-center justify-center">
				<button class="" on:click={() => saveSecret(false)}>Set</button>
			</div>
			<div class="flex justify-center items-end">
				<button class="bg-red-600 hover:bg-red-500" on:click={removeSecret}>Remove</button>
			</div>
		</div>
	{/if}
</td>
