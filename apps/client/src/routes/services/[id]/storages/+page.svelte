<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	let persistentStorages = data.persistentStorages;
	let template = data.template;
	import { page } from '$app/stores';
	import Storage from './components/Storage.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { appSession, trpc } from '$lib/store';

	const { id } = $page.params;
	async function refreshStorage() {
		const { data } = await trpc.services.getStorages.query({ id });
		persistentStorages = [...data.persistentStorages];
	}
	let services = Object.keys(template).map((service) => {
		if (template[service]?.name) {
			return {
				name: template[service].name,
				id: service
			};
		} else {
			return service;
		}
	});
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
			<div class="title font-bold pb-3">
				Persistent Volumes <Explainer
					position="dropdown-bottom"
					explanation="You can specify any folder that you want to be persistent across deployments.<br><br><span class='text-settings '>/example</span> means it will preserve <span class='text-settings '>/example</span> between deployments.<br><br>Your application's data is copied to <span class='text-settings '>/app</span> inside the container, you can preserve data under it as well, like <span class='text-settings '>/app/db</span>.<br><br>This is useful for storing data such as a <span class='text-settings '>database (SQLite)</span> or a <span class='text-settings '>cache</span>."
				/>
			</div>
		</div>
		{#if persistentStorages.filter((s) => s.predefined).length > 0}
			<div class="title">Predefined Volumes</div>
			<div class="w-full lg:px-0 px-4">
				<div class="grid grid-col-1 lg:grid-cols-2 pt-2 gap-2">
					<div class="font-bold uppercase">Container</div>
					<div class="font-bold uppercase">Volume ID : Mount Dir</div>
				</div>
			</div>

			{#each persistentStorages.filter((s) => s.predefined) as storage}
				{#key storage.id}
					<Storage on:refresh={refreshStorage} {storage} {services} />
				{/key}
			{/each}
		{/if}

		{#if persistentStorages.filter((s) => !s.predefined).length > 0}
			<div class="title" class:pt-10={persistentStorages.filter((s) => s.predefined).length > 0}>
				Custom Volumes
			</div>

			{#each persistentStorages.filter((s) => !s.predefined) as storage}
				{#key storage.id}
					<Storage on:refresh={refreshStorage} {storage} {services} />
				{/key}
			{/each}
		{/if}
		{#if $appSession.isAdmin}
			<div class="title" class:pt-10={persistentStorages.filter((s) => s.predefined).length > 0}>
				Add New Volume
			</div>
			<Storage on:refresh={refreshStorage} isNew {services} />
		{/if}
	</div>
</div>
