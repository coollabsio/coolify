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
			await del(`/services/${id}/storages`, { path: storage.path });
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

<div class="w-fullgrid gap-2">
	<div class="flex flex-col pb-2">
		<div class="flex flex-col lg:flex-row lg:space-y-0 space-y-2">
			<input
				class="w-full lg:w-64"
				bind:value={storage.path}
				required
				placeholder="eg: /sqlite.db"
			/>
			{#if isNew}
				<div class="flex items-center justify-center w-full lg:w-64">
					<button class="btn btn-sm btn-primary" on:click={() => saveStorage(true)}
						>{$t('forms.add')}</button
					>
				</div>
			{:else}
				<div class="flex flex-row items-center justify-center space-x-2 w-full lg:w-64">
					<div class="flex items-center justify-center">
						<button class="btn btn-sm btn-primary" on:click={() => saveStorage(false)}
							>{$t('forms.set')}</button
						>
					</div>
					<div class="flex justify-center">
						<button class="btn btn-sm btn-error" on:click={removeStorage}
							>{$t('forms.remove')}</button
						>
					</div>
				</div>
			{/if}
		</div>
	</div>
</div>
