<script lang="ts">
	export let isNew = false;
	export let storage: any = {};
	export let services: { id: string; name: string }[] = [];
	import { del, post } from '$lib/api';
	import { page } from '$app/stores';
	import { createEventDispatcher } from 'svelte';

	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast } from '$lib/store';
	const { id } = $page.params;

	const dispatch = createEventDispatcher();
	async function saveStorage(e: any) {
		try {
			const formData = new FormData(e.target);
			let isNewStorage = true;
			let newStorage: any = {
				id: null,
				containerId: null,
				path: null
			};
			for (let field of formData) {
				const [key, value] = field;
				newStorage[key] = value;
			}
			newStorage.path = newStorage.path.startsWith('/') ? newStorage.path : `/${newStorage.path}`;
			newStorage.path = newStorage.path.endsWith('/')
				? newStorage.path.slice(0, -1)
				: newStorage.path;
			newStorage.path.replace(/\/\//g, '/');
			await post(`/services/${id}/storages`, {
				path: newStorage.path,
				storageId: newStorage.id,
				containerId: newStorage.containerId,
				isNewStorage
			});
			dispatch('refresh');
			if (isNew) {
				storage.path = null;
				storage.id = null;
			}
			if (isNewStorage) {
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
	async function removeStorage(removableStorage: any) {
		try {
			const { id: storageId, volumeName, path } = removableStorage;
			const sure = confirm(
				`Are you sure you want to delete this storage ${volumeName + ':' + path}?`
			);
			if (sure) {
				await del(`/services/${id}/storages`, { storageId });
				dispatch('refresh');
				addToast({
					message: $t('application.storage.storage_deleted'),
					type: 'success'
				});
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="w-full lg:px-0 px-4">
	{#if storage.predefined}
		<div class="grid grid-col-1 lg:grid-cols-2 pt-2 gap-2">
			<div>
				<input
					id={storage.containerId}
					disabled
					readonly
					class="w-full"
					value={`${
						services.find((s) => s.id === storage.containerId)?.name ?? storage.containerId
					}`}
				/>
			</div>
			<div>
				<input
					id={storage.volumeName}
					disabled
					readonly
					class="w-full"
					value={`${storage.volumeName}:${storage.path}`}
				/>
			</div>
		</div>
	{:else if isNew}
		<form id="saveVolumesForm" on:submit|preventDefault={saveStorage} class="mt-8">
			<div class="grid grid-cols-2 lg:grid-cols-3 gap-2 lg:max-w-4xl">
				<label for="name" class="uppercase font-bold">Container</label>
				<label for="name" class="uppercase font-bold lg:col-span-2">Path</label>

				<select
					form="saveVolumesForm"
					name="containerId"
					class="w-full"
					disabled={storage.predefined}
					bind:value={storage.containerId}
				>
					{#if services.length === 1}
						{#if services[0].name}
							<option selected value={services[0].id}>{services[0].name}</option>
						{:else}
							<option selected value={services[0]}>{services[0]}</option>
						{/if}
					{:else}
						{#each services as service}
							{#if service.name}
								<option value={service.id}>{service.name}</option>
							{:else}
								<option value={service}>{service}</option>
							{/if}
						{/each}
					{/if}
				</select>

				<input
					name="path"
					disabled={storage.predefined}
					readonly={storage.predefined}
					class="w-full"
					bind:value={storage.path}
					required
					placeholder="eg: /sqlite.db"
				/>

				<button
					type="submit"
					class="btn btn-sm btn-primary w-full place-self-center col-span-2 lg:col-span-1"
					>{$t('forms.add')}</button
				>
			</div>
		</form>
	{:else}
		<div class="flex lg:flex-row flex-col items-center gap-2 py-1">
			<input
				disabled
				readonly
				class="w-full"
				value={`${services.find((s) => s.id === storage.containerId)?.name ?? storage.containerId}`}
			/>
			<input disabled readonly class="w-full" value={`${storage.volumeName}:${storage.path}`} />
			<button
				class="btn btn-sm btn-error"
				on:click|stopPropagation|preventDefault={() => removeStorage(storage)}
				>{$t('forms.remove')}</button
			>
		</div>
	{/if}
</div>
