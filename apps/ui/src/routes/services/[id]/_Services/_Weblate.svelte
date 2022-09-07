<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Weblate</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="adminPassword">Admin password</label>
	<CopyPasswordField
		name="adminPassword"
		id="adminPassword"
		isPasswordField
		value={service.weblate.adminPassword}
		readonly
		disabled
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlHost">PostgreSQL Host</label>
	<CopyPasswordField
		name="postgresqlHost"
		id="postgresqlHost"
		value={service.weblate.postgresqlHost}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlPort">PostgreSQL Port</label>
	<CopyPasswordField
		name="postgresqlPort"
		id="postgresqlPort"
		value={service.weblate.postgresqlPort}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlUser">PostgreSQL User</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.weblate.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlPassword">PostgreSQL Password</label>
	<CopyPasswordField
		name="postgresqlPassword"
		id="postgresqlPassword"
		isPasswordField
		value={service.weblate.postgresqlPassword}
		readonly
		disabled
	/>
</div>
