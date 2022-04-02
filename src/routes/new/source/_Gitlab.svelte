<script lang="ts">
	export let gitSource;
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	import { errorNotification } from '$lib/form';
	import { t } from '$lib/translations';
	import { onMount } from 'svelte';

	let nameEl;

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

<div class="flex justify-center pb-8">
	<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
		<div class="flex h-8 items-center space-x-2">
			<div class="text-xl font-bold text-white">{$t('forms.configuration')}</div>
			<button type="submit" class="bg-orange-600 hover:bg-orange-500">{$t('forms.save')}</button>
		</div>
		<div class="grid grid-cols-2 items-center px-10">
			<label for="type" class="text-base font-bold text-stone-100">{$t('forms.type')}</label>
			<select name="type" id="type" class="w-96" bind:value={gitSource.type}>
				<option value="github">GitHub</option>
				<option value="gitlab">GitLab</option>
				<option value="bitbucket">BitBucket</option>
			</select>
		</div>
		<div class="grid grid-cols-2 items-center px-10">
			<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
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
			<label for="htmlUrl" class="text-base font-bold text-stone-100">{$t('forms.html_url')}</label>
			<input
				type="url"
				name="htmlUrl"
				id="htmlUrl"
				placeholder="{$t('forms.eg')}: https://github.com"
				required
				bind:value={gitSource.htmlUrl}
			/>
		</div>
		<div class="grid grid-cols-2 items-center px-10">
			<label for="apiUrl" class="text-base font-bold text-stone-100">{$t('forms.api_url')}</label>
			<input
				name="apiUrl"
				type="url"
				id="apiUrl"
				placeholder="{$t('forms.eg')}: https://api.github.com"
				required
				bind:value={gitSource.apiUrl}
			/>
		</div>
	</form>
</div>
