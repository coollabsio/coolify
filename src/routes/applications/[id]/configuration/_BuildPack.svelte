<script lang="ts">
	import { goto } from '$app/navigation';

	import { page } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let buildPack;
	export let foundConfig;
	export let scanning;

	async function handleSubmit(buildPack) {
		try {
			if (foundConfig.buildPack !== buildPack)
				await post(`/applications/${id}.json`, { ...foundConfig });
			await post(`/applications/${id}/configuration/buildpack.json`, { buildPack });
			return await goto(from || `/applications/${id}`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<form on:submit|preventDefault={() => handleSubmit(buildPack.name)}>
	<button
		type="submit"
		class="relative box-selection text-xl font-bold flex {buildPack.hoverColor} {foundConfig?.buildPack ===
			buildPack.name && buildPack.color}"
		><span>{buildPack.fancyName}</span>
		{#if !scanning && foundConfig?.buildPack === buildPack.name}
			<span class="text-xs absolute bottom-0 pb-2">This one...</span>
		{/if}
	</button>
</form>
