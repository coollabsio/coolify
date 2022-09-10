<script lang="ts">
	import { goto } from '$app/navigation';

	import { page } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { findBuildPack } from '$lib/templates';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let buildPack: any;
	export let foundConfig: any;
	export let scanning: any;
	export let packageManager: any;

	async function handleSubmit(name: string) {
		try {
			const tempBuildPack = JSON.parse(
				JSON.stringify(findBuildPack(buildPack.name, packageManager))
			);

			delete tempBuildPack.name;
			delete tempBuildPack.fancyName;
			delete tempBuildPack.color;
			delete tempBuildPack.hoverColor;

			if (foundConfig?.buildPack !== name) {
				await post(`/applications/${id}`, { ...tempBuildPack, buildPack: name });
			}
			await post(`/applications/${id}/configuration/buildpack`, { buildPack: name });
			return await goto(from || `/applications/${id}`);
		} catch (error) {
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
			<span class="absolute bottom-0 pb-2 text-xs"
				>{$t('application.configuration.buildpack.choose_this_one')}</span
			>
		{/if}
	</button>
</form>
