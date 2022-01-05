<script lang="ts">
	export let database;
	import { page, session } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	let loading = false;
</script>

<div class="max-w-4xl mx-auto px-6">
	<form
		action="/databases/{id}.json"
		use:enhance={{
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 200);
			},
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
		method="post"
		class=" py-4"
	>
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Configurations</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:hover:bg-green-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="name">Name</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						name="name"
						id="name"
						value={database.name}
						required
					/>
				</div>
			</div>
		</div>
	</form>
</div>
