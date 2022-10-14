<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">MeiliSearch</div>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label for="masterKey">{$t('forms.admin_api_key')}</label>
	<CopyPasswordField
		id="masterKey"
		isPasswordField
		readonly
		disabled
		name="masterKey"
		value={service.meiliSearch.masterKey}
	/>
</div>
