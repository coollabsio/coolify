<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">SearXNG</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10 px-2">
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
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Redis</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10 px-2">
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
