<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/new/application.json`;
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
import { goto } from '$app/navigation';
	let autofocus;
	onMount(() => {
		autofocus.focus();
	});
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Application</div>
</div>
<div class="pt-10">
	<form
		action="/new/application.json"
		method="post"
		use:enhance={{
			result: async (res) => {
				const { id } = await res.json();
				goto(`/applications/${id}`)
			}
		}}
	>
		<div class="flex flex-col items-center space-y-4">
			<input
				name="name"
				placeholder="Application name"
				required
				bind:this={autofocus}
				value={name}
			/>
			<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
		</div>
	</form>
</div>
