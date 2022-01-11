<script lang="ts">
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
	export let buildPack;
	export let suggestion;
	export let scanning;
</script>

<form
	action="/applications/{id}/configuration/buildpack.json"
	method="post"
	use:enhance={{
		result: async () => {
			window.location.assign(from || `/applications/${id}`);
		}
	}}
>
	<input class="hidden" name="buildPack" value={buildPack.name} />
	<button
		type="submit"
		class="relative box-selection text-xl font-bold flex {buildPack.hoverColor} {suggestion === buildPack.name &&
			buildPack.color}"
		><span>{buildPack.fancyName}</span>
		{#if !scanning && suggestion === buildPack.name}
			<span class="text-xs absolute bottom-0 pb-2">This one...</span>
		{/if}
	</button>
</form>
