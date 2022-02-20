<script lang="ts">
	import { goto } from '$app/navigation';

	import { page } from '$app/stores';
	import { post } from '$lib/api';
	import { findBuildPack } from '$lib/components/templates';
	import { errorNotification } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let buildPack;
	export let foundConfig;
	export let scanning;
	export let packageManager;

	async function handleSubmit(name) {
		try {
			const tempBuildPack = JSON.parse(
				JSON.stringify(findBuildPack(buildPack.name, packageManager))
			);
			delete tempBuildPack.name;
			delete tempBuildPack.fancyName;
			delete tempBuildPack.color;
			delete tempBuildPack.hoverColor;

			if (foundConfig.buildPack !== name) {
				await post(`/applications/${id}.json`, { ...tempBuildPack });
			}
			await post(`/applications/${id}/configuration/buildpack.json`, { buildPack: name });
			return await goto(from || `/applications/${id}`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<form on:submit|preventDefault={() => handleSubmit(buildPack.name)}>
	<button
		type="submit"
		class="box-selection relative flex text-xl font-bold {buildPack.hoverColor} {foundConfig?.name ===
			buildPack.name && buildPack.color}"
		><span>{buildPack.fancyName}</span>
		{#if !scanning && foundConfig?.name === buildPack.name}
			<span class="absolute bottom-0 pb-2 text-xs">Choose this one...</span>
		{/if}
	</button>
</form>
