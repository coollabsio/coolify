<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';

	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">VSCode Server</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10 px-2">
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
