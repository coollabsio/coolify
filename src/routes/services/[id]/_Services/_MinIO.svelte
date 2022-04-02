<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MinIO Server</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rootUser">{$t('forms.root_user')}</label>
	<input
		name="rootUser"
		id="rootUser"
		placeholder="User to login"
		value={service.minio.rootUser}
		disabled
		readonly
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rootUserPassword">{$t('forms.roots_password')}</label>
	<CopyPasswordField
		id="rootUserPassword"
		isPasswordField
		readonly
		disabled
		name="rootUserPassword"
		value={service.minio.rootUserPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="publicPort">API Port</label>
	<input
		name="publicPort"
		id="publicPort"
		value={service.minio.publicPort}
		disabled
		readonly
		placeholder={$t('forms.generated_automatically_after_start')}
	/>
</div>
