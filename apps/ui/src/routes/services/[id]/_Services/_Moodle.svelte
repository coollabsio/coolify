<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let readOnly: any;
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Moodle</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="email">{$t('forms.default_email_address')}</label>
	<input 
		class="w-full"
		name="email"
		id="email"
		required
		readonly={readOnly}
		disabled={readOnly}
		placeholder={$t('forms.email')}
		value={service.moodle.defaultEmail}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="defaultUsername">Default Username</label>
	<CopyPasswordField
		id="defaultUsername"
		required
		readonly={readOnly}
		disabled={readOnly}
		name="defaultUsername"
		value={service.moodle.defaultUsername}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="defaultPassword">{$t('forms.default_password')}</label>
	<CopyPasswordField
		id="defaultPassword"
		isPasswordField
		required
		readonly={readOnly}
		disabled={readOnly}
		name="defaultPassword"
		value={service.moodle.defaultPassword}
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MariaDB</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="mariadbUser">{$t('forms.username')}</label>
	<CopyPasswordField
		name="mariadbUser"
		id="mariadbUser"
		value={service.moodle.mariadbUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="mariadbPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="mariadbPassword"
		isPasswordField
		readonly
		disabled
		name="mariadbPassword"
		value={service.moodle.mariadbPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="mariadbDatabase">{$t('index.database')}</label>
	<input 
		class="w-full"
		name="mariadbDatabase"
		id="mariadbDatabase"
		required
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.moodle.mariadbDatabase}
		placeholder="{$t('forms.eg')}: moodle_db"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="mariadbRootUser">{$t('forms.root_db_user')}</label>
	<CopyPasswordField
		id="mariadbRootUser"
		readonly
		disabled
		name="mariadbRootUser"
		value={service.moodle.mariadbRootUser}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="mariadbRootUserPassword">{$t('forms.root_db_password')}</label>
	<CopyPasswordField
		id="mariadbRootUserPassword"
		isPasswordField
		readonly
		disabled
		name="mariadbRootUserPassword"
		value={service.moodle.mariadbRootUserPassword}
	/>
</div>
