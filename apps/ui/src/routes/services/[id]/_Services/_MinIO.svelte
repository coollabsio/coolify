<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';

	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MinIO</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rootUser">{$t('forms.root_user')}</label>
	<input
		name="rootUser"
		id="rootUser"
		placeholder={$t('forms.username')}
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
{#if !service.minio.apiFqdn}
	<div class="grid grid-cols-2 items-center px-10">
		<label for="publicPort">{$t('forms.api_port')}</label>
		<input
			name="publicPort"
			id="publicPort"
			value={service.minio.publicPort}
			disabled
			readonly
			placeholder={$t('forms.generated_automatically_after_start')}
		/>
	</div>
{/if}
