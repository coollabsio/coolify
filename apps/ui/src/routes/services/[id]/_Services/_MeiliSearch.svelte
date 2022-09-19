<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MeiliSearch</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10 px-2">
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
