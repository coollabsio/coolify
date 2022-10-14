<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Taiga</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="secretKey">Secret Key</label>
	<CopyPasswordField
		name="secretKey"
		id="secretKey"
		isPasswordField
		value={service.taiga.secretKey}
		readonly
		disabled
	/>
</div>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Django</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="djangoAdminUser">Admin User</label>
	<CopyPasswordField
		name="djangoAdminUser"
		id="djangoAdminUser"
		value={service.taiga.djangoAdminUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="djangoAdminPassword">Admin Password</label>
	<CopyPasswordField
		name="djangoAdminPassword"
		id="djangoAdminPassword"
		isPasswordField
		value={service.taiga.djangoAdminPassword}
		readonly
		disabled
	/>
</div>
<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">RabbitMQ</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="rabbitMQUser">User</label>
	<CopyPasswordField
		name="rabbitMQUser"
		id="rabbitMQUser"
		value={service.taiga.rabbitMQUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="rabbitMQPassword">Password</label>
	<CopyPasswordField
		name="rabbitMQPassword"
		id="rabbitMQPassword"
		isPasswordField
		value={service.taiga.rabbitMQPassword}
		readonly
		disabled
	/>
</div>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">PostgreSQL</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="postgresqlHost">PostgreSQL Host</label>
	<CopyPasswordField
		name="postgresqlHost"
		id="postgresqlHost"
		value={service.taiga.postgresqlHost}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="postgresqlPort">PostgreSQL Port</label>
	<CopyPasswordField
		name="postgresqlPort"
		id="postgresqlPort"
		value={service.taiga.postgresqlPort}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="postgresqlUser">PostgreSQL User</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.taiga.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-4">
	<label class="text-base font-bold text-stone-100" for="postgresqlPassword">PostgreSQL Password</label>
	<CopyPasswordField
		name="postgresqlPassword"
		id="postgresqlPassword"
		isPasswordField
		value={service.taiga.postgresqlPassword}
		readonly
		disabled
	/>
</div>
