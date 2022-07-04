<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let readOnly: any;
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Ghost</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="email">{$t('forms.default_email_address')}</label>
	<input
		name="email"
		id="email"
		disabled
		readonly
		placeholder={$t('forms.email')}
		value={service.ghost.defaultEmail}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultPassword">{$t('forms.default_password')}</label>
	<CopyPasswordField
		id="defaultPassword"
		isPasswordField
		readonly
		disabled
		name="defaultPassword"
		value={service.ghost.defaultPassword}
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MariaDB</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbUser">{$t('forms.username')}</label>
	<CopyPasswordField
		name="mariadbUser"
		id="mariadbUser"
		value={service.ghost.mariadbUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="mariadbPassword"
		isPasswordField
		readonly
		disabled
		name="mariadbPassword"
		value={service.ghost.mariadbPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbDatabase">{$t('index.database')}</label>
	<input
		name="mariadbDatabase"
		id="mariadbDatabase"
		required
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.ghost.mariadbDatabase}
		placeholder="{$t('forms.eg')}: ghost_db"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbRootUser">{$t('forms.root_db_user')}</label>
	<CopyPasswordField
		id="mariadbRootUser"
		readonly
		disabled
		name="mariadbRootUser"
		value={service.ghost.mariadbRootUser}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbRootUserPassword">{$t('forms.root_db_password')}</label>
	<CopyPasswordField
		id="mariadbRootUserPassword"
		isPasswordField
		readonly
		disabled
		name="mariadbRootUserPassword"
		value={service.ghost.mariadbRootUserPassword}
	/>
</div>
