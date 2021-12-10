<script lang="ts">
	import { enhance } from '$lib/form';
	import { onMount } from 'svelte';

	let autofocus;
	onMount(() => {
		autofocus.focus();
	});
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Team</div>
</div>

<div class="pt-10">
	<form
		action="/new/team.json"
		method="post"
		use:enhance={{
			result: async (res) => {
				const { id } = await res.json();
				window.location.assign(`/teams/${id}`);
			}
		}}
	>
		<div class="flex flex-col items-center space-y-4">
			<input name="name" placeholder="Team name" required bind:this={autofocus} />
			<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
		</div>
	</form>
</div>
