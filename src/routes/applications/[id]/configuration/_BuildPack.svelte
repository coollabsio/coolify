<script lang="ts">
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
	export let buildPack;
	export let foundConfig;
	export let scanning;
</script>

<form
	action="/applications/{id}/configuration/buildpack.json"
	method="post"
	use:enhance={{
		beforeSubmit: async () => {
			const form = new FormData();
			form.append('buildPack', foundConfig.buildPack);
			if (foundConfig.installCommand) form.append('installCommand', foundConfig.installCommand);
			if (foundConfig.startCommand) form.append('startCommand', foundConfig.startCommand);
			if (foundConfig.buildCommand) form.append('buildCommand', foundConfig.buildCommand);
			if (foundConfig.publishDirectory)
				form.append('publishDirectory', foundConfig.publishDirectory);
			form.append('port', foundConfig.port);
			try {
				await fetch(`/applications/${id}.json`, {
					method: 'POST',
					body: form
				});
			} catch (e) {
				console.error(e);
			}
		},
		result: async () => {
			window.location.assign(from || `/applications/${id}`);
		}
	}}
>
	<input class="hidden" name="buildPack" value={buildPack.name} />
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
