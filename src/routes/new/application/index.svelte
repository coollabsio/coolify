<script lang="ts">
	import { enhance } from '$lib/form';
	import { onMount } from 'svelte';
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
				window.location.assign(`/applications/${id}`);
			}
		}}
	>
		<div class="flex flex-col items-center space-y-4">
			<input name="name" placeholder="Application name" required bind:this={autofocus} />
			<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
		</div>
	</form>
</div>
