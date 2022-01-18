<script lang="ts">
import { goto } from '$app/navigation';

	import Explainer from '$lib/components/Explainer.svelte';

	import { enhance } from '$lib/form';
	import { gitSourcePayload } from '$lib/store';
	import { onMount } from 'svelte';

	let nameEl;
	let organizationEl;

	onMount(() => {
		nameEl.focus();
	});
</script>

<div class="flex justify-center pb-8">
	<form
		action="/new/source.json"
		method="post"
		use:enhance={{
			result: async (res) => {
				const { id } = await res.json();
				goto(`/sources/${id}`);
				// window.location.assign(`/sources/${id}`);
			}
		}}
		class="grid grid-flow-row gap-2 py-4"
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button type="submit" class="bg-orange-600 hover:bg-orange-500">Save</button>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="type">Type</label>

			<div class="col-span-2">
				<select name="type" id="type" class="w-96" bind:value={$gitSourcePayload.type}>
					<option value="github">GitHub</option>
					<option value="gitlab">GitLab</option>
					<option value="bitbucket">BitBucket</option>
				</select>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input
					name="name"
					id="name"
					placeholder="GitHub.com"
					required
					bind:this={nameEl}
					bind:value={$gitSourcePayload.name}
				/>
			</div>
		</div>

		<div class="grid grid-cols-3 items-center">
			<label for="htmlUrl">HTML URL</label>
			<div class="col-span-2">
				<input
					type="url"
					name="htmlUrl"
					id="htmlUrl"
					placeholder="eg: https://github.com"
					required
					bind:value={$gitSourcePayload.htmlUrl}
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="apiUrl">API URL</label>
			<div class="col-span-2">
				<input
					name="apiUrl"
					type="url"
					id="apiUrl"
					placeholder="eg: https://api.github.com"
					required
					bind:value={$gitSourcePayload.apiUrl}
				/>
			</div>
		</div>
	</form>
</div>
