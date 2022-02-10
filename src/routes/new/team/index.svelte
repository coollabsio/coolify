<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/common/getUniqueName.json`;
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
	import { errorNotification } from '$lib/form';
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	let autofocus;
	onMount(() => {
		autofocus.focus();
	});
	async function handleSubmit() {
		if (name) {
			try {
				const { id } = await post('/new/team.json', { name });
				return await goto(`/teams/${id}`);
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Add New Team</div>
</div>

<div class="pt-10">
	<form on:submit|preventDefault={handleSubmit}>
		<div class="flex flex-col items-center space-y-4">
			<input name="name" placeholder="Team name" required bind:this={autofocus} bind:value={name} />
			<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
		</div>
	</form>
</div>
