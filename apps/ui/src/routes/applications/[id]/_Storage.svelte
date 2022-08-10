<script lang="ts">
	export let isNew = false;
	export let storage: any = {
		id: null,
		path: null
	};
	import { del, post } from '$lib/api';
	import { page } from '$app/stores';
	import { createEventDispatcher } from 'svelte';

	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast } from '$lib/store';
	const { id } = $page.params;

	const dispatch = createEventDispatcher();
	async function saveStorage(newStorage = false) {
		try {
			if (!storage.path) return errorNotification($t('application.storage.path_is_required'));
			storage.path = storage.path.startsWith('/') ? storage.path : `/${storage.path}`;
			storage.path = storage.path.endsWith('/') ? storage.path.slice(0, -1) : storage.path;
			storage.path.replace(/\/\//g, '/');
			await post(`/applications/${id}/storages`, {
				path: storage.path,
				storageId: storage.id,
				newStorage
			});
			dispatch('refresh');
			if (isNew) {
				storage.path = null;
				storage.id = null;
			}
			if (newStorage) {
				addToast({
					message: $t('application.storage.storage_saved'),
					type: 'success'
				});
			} else {
				addToast({
					message: $t('application.storage.storage_updated'),
					type: 'success'
				});
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function removeStorage() {
		try {
			await del(`/applications/${id}/storages`, { path: storage.path });
			dispatch('refresh');
			addToast({
				message: $t('application.storage.storage_deleted'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<td>
	<input
		bind:value={storage.path}
		required
		placeholder="eg: /sqlite.db"
	/>
</td>
<td>
	{#if isNew}
		<div class="flex items-center justify-center">
			<button class="btn btn-sm bg-applications" on:click={() => saveStorage(true)}
				>{$t('forms.add')}</button
			>
		</div>
	{:else}
		<div class="flex flex-row justify-center space-x-2">
			<div class="flex items-center justify-center">
				<button class="btn btn-sm bg-applications" on:click={() => saveStorage(false)}
					>{$t('forms.set')}</button
				>
			</div>
			<div class="flex justify-center items-end">
				<button class="btn btn-sm bg-red-600 hover:bg-red-500" on:click={removeStorage}
					>{$t('forms.remove')}</button
				>
			</div>
		</div>
	{/if}
</td>
