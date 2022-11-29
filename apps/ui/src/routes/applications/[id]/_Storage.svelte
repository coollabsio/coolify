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

<div class="w-full lg:px-0 px-4">
	{#if storage.predefined}
		<div class="flex flex-col lg:flex-row gap-4 pb-2">
			<input disabled readonly class="w-full" value={storage.id} />
			<input disabled readonly class="w-full" bind:value={storage.path} />
		</div>
	{:else}
		<div class="flex gap-4 pb-2" class:pt-8={isNew}>
			{#if storage.applicationId}
				{#if storage.oldPath}
					<input
						disabled
						readonly
						class="w-full"
						value="{storage.applicationId}{storage.path.replace(/\//gi, '-').replace('-app', '')}"
					/>
				{:else}
					<input
						disabled
						readonly
						class="w-full"
						value="{storage.applicationId}{storage.path.replace(/\//gi, '-')}"
					/>
				{/if}
			{/if}
			<input
				disabled={!isNew}
				readonly={!isNew}
				class="w-full"
				bind:value={storage.path}
				required
				placeholder="eg: /data"
			/>

			<div class="flex items-center justify-center">
				{#if isNew}
					<div class="w-full lg:w-64">
						<button class="btn btn-sm btn-primary w-full" on:click={() => saveStorage(true)}
							>{$t('forms.add')}</button
						>
					</div>
				{:else}
					<div class="flex justify-center">
						<button class="btn btn-sm btn-error" on:click={removeStorage}
							>{$t('forms.remove')}</button
						>
					</div>
				{/if}
			</div>
		</div>
	{/if}
</div>
