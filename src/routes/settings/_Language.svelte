<script>
	import { t } from '$lib/translations';
	import Cookies from 'js-cookie';
	import langs from '$lib/lang.json';
	function setLocale(locale) {
		Cookies.set('lang', locale);
		return window.location.reload();
	}
</script>

<div class="grid grid-cols-2 items-start pb-4">
	<div class="flex-col">
		<div class="text-base font-bold text-stone-100">
			{$t('setting.change_language')}
		</div>
	</div>
	<div class="items-center justify-start space-x-2 text-left">
		{#each Object.entries(langs) as [lang, name]}
			<button on:click={() => setLocale(lang)}>Change to {name}</button>
		{/each}
	</div>
</div>
