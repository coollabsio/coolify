<script lang="ts">
	export let isNew = false;
	export let storage = {
		id: null,
		path: null
	};
	import { del, post } from '$lib/api';
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/form';
	const { id } = $page.params;

	async function saveStorage() {
		try {
			storage.path = storage.path.startsWith('/') ? storage.path : `/${storage.path}`;
			storage.path = storage.path.endsWith('/') ? storage.path.slice(0, -1) : storage.path;
			storage.path.replace(/\/\//g, '/');
			await post(`/applications/${id}/storage.json`, {
				path: storage.path
			});
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function removeStorage() {
		try {
			await del(`/applications/${id}/storage.json`, { path: storage.path });
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<td>
	<input
		readonly={!isNew}
		bind:value={storage.path}
		required
		placeholder="eg: /sqlite.db"
		class=" border border-dashed border-coolgray-300"
	/>
</td>
<td>
	<div class="flex items-center justify-center px-2">
		<button class="bg-green-600 hover:bg-green-500" on:click={saveStorage}>Add</button>
	</div>
	<div class="flex items-center justify-center px-2">
		<button class="bg-green-600 hover:bg-green-500" on:click={removeStorage}>Remove</button>
	</div>
</td>
