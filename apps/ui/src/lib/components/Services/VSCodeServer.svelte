<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';

	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">VScode Server</div>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label for="password">{$t('forms.password')}</label>
	<CopyPasswordField
		id="password"
		isPasswordField
		readonly
		disabled
		name="password"
		value={service.vscodeserver.password}
	/>
</div>
