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
		class="box-selection text-xl font-bold"
		class:border-green-500={buildPack.name === 'node' && suggestion === 'node'}
		class:border-sky-500={buildPack.name === 'docker' && suggestion === 'docker'}
		class:border-red-500={buildPack.name === 'static' && suggestion === 'static'}
		class:hover:border-green-500={buildPack.name === 'node'}
		class:hover:border-red-500={buildPack.name === 'static'}
		class:hover:border-sky-500={buildPack.name === 'docker'}
		>{buildPack.name}
	</button>
</form>
<div class="text-center text-xs font-bold uppercase pt-2">
	{#if !scanning && suggestion === buildPack.name}
		Pick this one!
	{/if}
</div>
