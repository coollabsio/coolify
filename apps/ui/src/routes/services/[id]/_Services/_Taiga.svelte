<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Taiga</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="secretKey">Secret Key</label>
	<CopyPasswordField
		name="secretKey"
		id="secretKey"
		isPasswordField
		value={service.taiga.secretKey}
		readonly
		disabled
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Django</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="djangoAdminUser">Admin User</label>
	<CopyPasswordField
		name="djangoAdminUser"
		id="djangoAdminUser"
		value={service.taiga.djangoAdminUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="djangoAdminPassword">Admin Password</label>
	<CopyPasswordField
		name="djangoAdminPassword"
		id="djangoAdminPassword"
		isPasswordField
		value={service.taiga.djangoAdminPassword}
		readonly
		disabled
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">RabbitMQ</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="rabbitMQUser">User</label>
	<CopyPasswordField
		name="rabbitMQUser"
		id="rabbitMQUser"
		value={service.taiga.rabbitMQUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rabbitMQPassword">Password</label>
	<CopyPasswordField
		name="rabbitMQPassword"
		id="rabbitMQPassword"
		isPasswordField
		value={service.taiga.rabbitMQPassword}
		readonly
		disabled
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlHost">PostgreSQL Host</label>
	<CopyPasswordField
		name="postgresqlHost"
		id="postgresqlHost"
		value={service.taiga.postgresqlHost}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPort">PostgreSQL Port</label>
	<CopyPasswordField
		name="postgresqlPort"
		id="postgresqlPort"
		value={service.taiga.postgresqlPort}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlUser">PostgreSQL User</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.taiga.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPassword">PostgreSQL Password</label>
	<CopyPasswordField
		name="postgresqlPassword"
		id="postgresqlPassword"
		isPasswordField
		value={service.taiga.postgresqlPassword}
		readonly
		disabled
	/>
</div>
