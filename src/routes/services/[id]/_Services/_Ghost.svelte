<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let readOnly;
	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Ghost</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="email">Default Email Address</label>
	<input
		name="email"
		id="email"
		disabled
		readonly
		placeholder="Email address"
		value={service.ghost.defaultEmail}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultPassword">Default Password</label>
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
	<label for="mariadbUser">Username</label>
	<CopyPasswordField
		name="mariadbUser"
		id="mariadbUser"
		value={service.ghost.mariadbUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbPassword">Password</label>
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
	<label for="mariadbDatabase">Database</label>
	<input
		name="mariadbDatabase"
		id="mariadbDatabase"
		required
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.ghost.mariadbDatabase}
		placeholder="eg: ghost_db"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbRootUser">Root DB User</label>
	<CopyPasswordField
		id="mariadbRootUser"
		isPasswordField
		readonly
		disabled
		name="mariadbRootUser"
		value={service.ghost.mariadbRootUser}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mariadbRootUserPassword">Root DB Password</label>
	<CopyPasswordField
		id="mariadbRootUserPassword"
		isPasswordField
		readonly
		disabled
		name="mariadbRootUserPassword"
		value={service.ghost.mariadbRootUserPassword}
	/>
</div>
