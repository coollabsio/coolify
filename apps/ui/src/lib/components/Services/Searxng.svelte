<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">SearXNG</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label for="secretKey">Secret Key</label>
	<CopyPasswordField
		name="secretKey"
		id="secretKey"
		isPasswordField
		value={service.searxng.secretKey}
		readonly
		disabled
	/>
</div>
<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Redis</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label for="redisPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		name="redisPassword"
		id="redisPassword"
		isPasswordField
		value={service.searxng.redisPassword}
		readonly
		disabled
	/>
</div>
