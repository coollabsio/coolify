<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Weblate</div>
</div>

<div class="grid grid-cols-2 items-center px-4">
	<label for="adminPassword">Admin password</label>
	<CopyPasswordField
		name="adminPassword"
		id="adminPassword"
		isPasswordField
		value={service.weblate.adminPassword}
		readonly
		disabled
	/>
</div>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">PostgreSQL</div>
</div>
<div class="space-y-2">
	<div class="grid grid-cols-2 items-center px-4">
		<label for="postgresqlHost">Host</label>
		<CopyPasswordField
			name="postgresqlHost"
			id="postgresqlHost"
			value={service.weblate.postgresqlHost}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-4">
		<label for="postgresqlPort">Port</label>
		<CopyPasswordField
			name="postgresqlPort"
			id="postgresqlPort"
			value={service.weblate.postgresqlPort}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-4">
		<label for="postgresqlUser">User</label>
		<CopyPasswordField
			name="postgresqlUser"
			id="postgresqlUser"
			value={service.weblate.postgresqlUser}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-4">
		<label for="postgresqlPassword">Password</label>
		<CopyPasswordField
			name="postgresqlPassword"
			id="postgresqlPassword"
			isPasswordField
			value={service.weblate.postgresqlPassword}
			readonly
			disabled
		/>
	</div>
</div>
