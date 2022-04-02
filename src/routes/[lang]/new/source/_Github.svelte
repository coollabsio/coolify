<script lang="ts">
	export let gitSource;
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	import Explainer from '$lib/components/Explainer.svelte';

	import { errorNotification } from '$lib/form';
	import { onMount } from 'svelte';

	let nameEl;
	let organizationEl;

	onMount(() => {
		nameEl.focus();
	});
	async function handleSubmit() {
		try {
			const { id } = await post(`/new/source.json`, { ...gitSource });
			return await goto(`/sources/${id}/`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="mx-auto max-w-4xl px-6">
	<div class="flex justify-center pb-8">
		<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
			<div class="flex h-8 items-center space-x-2">
				<div class="text-xl font-bold text-white">Configuration</div>
				<button type="submit" class="bg-orange-600 hover:bg-orange-500">Save</button>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="type" class="text-base font-bold text-stone-100">Type</label>

				<select name="type" id="type" class="w-96" bind:value={gitSource.type}>
					<option value="github">GitHub</option>
					<option value="gitlab">GitLab</option>
					<option value="bitbucket">BitBucket</option>
				</select>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="name" class="text-base font-bold text-stone-100">Name</label>
				<input
					name="name"
					id="name"
					placeholder="GitHub.com"
					required
					bind:this={nameEl}
					bind:value={gitSource.name}
				/>
			</div>

			<div class="grid grid-cols-2 items-center px-10">
				<label for="htmlUrl" class="text-base font-bold text-stone-100">HTML URL</label>
				<input
					type="url"
					name="htmlUrl"
					id="htmlUrl"
					placeholder="eg: https://github.com"
					required
					bind:value={gitSource.htmlUrl}
				/>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="apiUrl" class="text-base font-bold text-stone-100">API URL</label>
				<input
					name="apiUrl"
					type="url"
					id="apiUrl"
					placeholder="eg: https://api.github.com"
					required
					bind:value={gitSource.apiUrl}
				/>
			</div>
			<div class="grid grid-cols-2 px-10">
				<div class="flex flex-col">
					<label for="organization" class="pt-2 text-base font-bold text-stone-100"
						>Organization</label
					>
					<Explainer
						text="Fill it if you would like to use an organization's as your Git Source. Otherwise your user will be used."
					/>
				</div>
				<input
					name="organization"
					id="organization"
					placeholder="eg: coollabsio"
					bind:value={gitSource.organization}
					bind:this={organizationEl}
				/>
			</div>
		</form>
	</div>
</div>
