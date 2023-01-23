<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	const application = data.application.data;
	let persistentStorages = data.persistentStorages;
	import { page } from '$app/stores';
	import Storage from './components/Storage.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { trpc } from '$lib/store';

	let composeJson: any = JSON.parse(application?.dockerComposeFile || '{}');
	let predefinedVolumes: any[] = [];
	if (composeJson?.services) {
		for (const [_, service] of Object.entries(composeJson.services)) {
			if (service?.volumes) {
				for (const [_, volumeName] of Object.entries(service.volumes)) {
					let [volume, target] = volumeName.split(':');
					if (volume === '.') {
						volume = target;
					}
					if (!target) {
						target = volume;
						volume = `${application.id}${volume.replace(/\//gi, '-').replace(/\./gi, '')}`;
					} else {
						volume = `${application.id}${volume.replace(/\//gi, '-').replace(/\./gi, '')}`;
					}
					predefinedVolumes.push({ id: volume, path: target, predefined: true });
				}
			}
		}
	}
	const { id } = $page.params;
	async function refreshStorage() {
		const { data } = await trpc.applications.getStorages.query({ id });
		persistentStorages = [...data.persistentStorages];
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
			<div class="title font-bold pb-3">Persistent Volumes</div>
		</div>
		{#if predefinedVolumes.length > 0}
			<div class="title">Predefined Volumes</div>
			<div class="w-full lg:px-0 px-4">
				<div class="grid grid-col-1 lg:grid-cols-2 py-2 gap-2">
					<div class="font-bold uppercase">Volume Id</div>
					<div class="font-bold uppercase">Mount Dir</div>
				</div>
			</div>

			<div class="gap-4">
				{#each predefinedVolumes as storage}
					{#key storage.id}
						<Storage on:refresh={refreshStorage} {storage} />
					{/key}
				{/each}
			</div>
		{/if}
		{#if persistentStorages.length > 0}
			<div class="title" class:pt-10={predefinedVolumes.length > 0}>Custom Volumes</div>
		{/if}
		{#each persistentStorages as storage}
			{#key storage.id}
				<Storage on:refresh={refreshStorage} {storage} />
			{/key}
		{/each}
		<div class="Preview Secrets" class:pt-10={predefinedVolumes.length > 0}>
			Add New Volume <Explainer
				position="dropdown-bottom"
				explanation="You can specify any folder that you want to be persistent across deployments.<br><br><span class='text-settings '>/example</span> means it will preserve <span class='text-settings '>/example</span> between deployments.<br><br>Your application's data is copied to <span class='text-settings '>/app</span> inside the container, you can preserve data under it as well, like <span class='text-settings '>/app/db</span>.<br><br>This is useful for storing data such as a <span class='text-settings '>database (SQLite)</span> or a <span class='text-settings '>cache</span>."
			/>
		</div>
		<Storage on:refresh={refreshStorage} isNew />
	</div>
</div>
