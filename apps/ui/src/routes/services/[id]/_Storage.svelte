<script lang="ts">
	export let isNew = false;
	export let storage: any = {
		id: null,
		path: null
	};
	import { del, post } from '$lib/api';
	import { page } from '$app/stores';
	import { createEventDispatcher } from 'svelte';

	import { toast } from '@zerodevx/svelte-toast';
	import { errorNotification } from '$lib/common';
	const { id } = $page.params;

	const dispatch = createEventDispatcher();
	async function saveStorage(newStorage = false) {
		try {
			if (!storage.path) return errorNotification('Path is required.');
			storage.path = storage.path.startsWith('/') ? storage.path : `/${storage.path}`;
			storage.path = storage.path.endsWith('/') ? storage.path.slice(0, -1) : storage.path;
			storage.path.replace(/\/\//g, '/');
			await post(`/services/${id}/storages`, {
				path: storage.path,
				storageId: storage.id,
				newStorage
			});
			dispatch('refresh');
			if (isNew) {
				storage.path = null;
				storage.id = null;
			}
			if (newStorage) toast.push('Storage saved.');
			else toast.push('Storage updated.');
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function removeStorage() {
		try {
			await del(`/services/${id}/storages`, { path: storage.path });
			dispatch('refresh');
			toast.push('Storage deleted.');
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		if (isNew) {
			await saveStorage(true)
		} else {
			await saveStorage(false)
		}
	}
</script>

<td>
	<form on:submit|preventDefault={handleSubmit}>
		<input
			bind:value={storage.path}
			required
			placeholder="eg: /data"
			class=" border border-dashed border-coolgray-300"
		/>
	</form>
</td>
<td>
	{#if isNew}
		<div class="flex items-center justify-center">
			<button class="bg-green-600 hover:bg-green-500" on:click={() => saveStorage(true)}>Add</button
			>
		</div>
	{:else}
		<div class="flex flex-row justify-center space-x-2">
			<div class="flex items-center justify-center">
				<button class="" on:click={() => saveStorage(false)}>Set</button>
			</div>
			<div class="flex justify-center items-end">
				<button class="bg-red-600 hover:bg-red-500" on:click={removeStorage}>Remove</button>
			</div>
		</div>
	{/if}
</td>
