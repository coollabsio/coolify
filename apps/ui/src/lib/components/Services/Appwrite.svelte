<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import Select from 'svelte-select';
	export let service: any;
	export let readOnly: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Appwrite</div>
</div>

<div class="space-y-2 px-4">
	<div class="grid grid-cols-2 items-center">
		<label for="opensslKeyV1">Encryption Key</label>
		<CopyPasswordField
			name="opensslKeyV1"
			id="opensslKeyV1"
			isPasswordField
			value={service.appwrite.opensslKeyV1}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="executorSecret">Executor Secret</label>
		<CopyPasswordField
			name="executorSecret"
			id="executorSecret"
			isPasswordField
			value={service.appwrite.executorSecret}
			readonly
			disabled
		/>
	</div>
</div>
<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">MariaDB</div>
</div>
<div class="space-y-2 px-4">	
	<div class="grid grid-cols-2 items-center">
		<label for="mariadbUser">{$t('forms.username')}</label>
		<CopyPasswordField
			name="mariadbUser"
			id="mariadbUser"
			value={service.appwrite.mariadbUser}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="mariadbPassword">{$t('forms.password')}</label>
		<CopyPasswordField
			id="mariadbPassword"
			isPasswordField
			readonly
			disabled
			name="mariadbPassword"
			value={service.appwrite.mariadbPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="mariadbRootUser">Root User</label>
		<CopyPasswordField
			name="mariadbRootUser"
			id="mariadbRootUser"
			value={service.appwrite.mariadbRootUser}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="mariadbRootUserPassword">Root Password</label>
		<CopyPasswordField
			id="mariadbRootUserPassword"
			isPasswordField
			readonly
			disabled
			name="mariadbRootUserPassword"
			value={service.appwrite.mariadbRootUserPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="mariadbDatabase">{$t('index.database')}</label>
		<CopyPasswordField
			name="mariadbDatabase"
			id="mariadbDatabase"
			value={service.appwrite.mariadbDatabase}
			readonly
			disabled
		/>
	</div>
</div>
