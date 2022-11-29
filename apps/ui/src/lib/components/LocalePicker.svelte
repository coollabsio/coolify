<script>
	import { locale, locales } from '$lib/translations';
</script>

<div>
	<select bind:value={$locale} class="w-14">
		{#each $locales as l}
			<option value={l}>{l}</option>
		{/each}
	</select>
</div>
