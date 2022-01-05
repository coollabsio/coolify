<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/new/database.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	export let name;
	import { enhance } from '$lib/form';
	import { onMount } from 'svelte';
	let autofocus;

	onMount(() => {
		autofocus.focus();
	});
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Database</div>
</div>
<div class="pt-10">
	<form
		action="/new/database.json"
		method="post"
		use:enhance={{
			result: async (res) => {
				const { id } = await res.json();
				window.location.assign(`/databases/${id}`);
			}
		}}
	>
		<div class="flex flex-col items-center space-y-4">
			<input name="name" placeholder="Database name" required bind:this={autofocus} value={name} />
			<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
		</div>
	</form>
</div>
