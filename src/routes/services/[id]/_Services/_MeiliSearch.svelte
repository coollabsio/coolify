<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MeiliSearch</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="masterKey">Admin API key</label>
	<CopyPasswordField
		id="masterKey"
		isPasswordField
		readonly
		disabled
		name="masterKey"
		value={service.meiliSearch.masterKey}
	/>
</div>
