<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
	export let isRunning;
	export let readOnly;
</script>

<div class="font-bold flex space-x-1 py-5">
	<div class="text-xl tracking-tight mr-4">Wordpress</div>
</div>

<div class="grid grid-cols-3 items-center">
	<label for="extraConfig">Extra Config</label>
	<div class="col-span-2 ">
		<textarea
			disabled={isRunning}
			readonly={isRunning}
			class:resize-none={isRunning}
			rows={isRunning ? 1 : 5}
			name="extraConfig"
			id="extraConfig"
			placeholder={!isRunning ? `eg:

define('WP_ALLOW_MULTISITE', true);
define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);` : null}>{service.wordpress.extraConfig}</textarea
		>
	</div>
</div>
<div class="font-bold flex space-x-1 py-5">
	<div class="text-xl tracking-tight mr-4">MySQL</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="mysqlDatabase">Database</label>
	<div class="col-span-2 ">
		<input
			name="mysqlDatabase"
			id="mysqlDatabase"
			required
			readonly={readOnly}
			disabled={readOnly}
			bind:value={service.wordpress.mysqlDatabase}
			placeholder="eg: wordpress_db"
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="mysqlRootUser">Root User</label>
	<div class="col-span-2 ">
		<input
			name="mysqlRootUser"
			id="mysqlRootUser"
			placeholder="MySQL Root User"
			value={service.wordpress.mysqlRootUser}
			disabled
			readonly
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="mysqlRootUserPassword">Root's Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			id="mysqlRootUserPassword"
			isPasswordField
			readonly
			disabled
			name="mysqlRootUserPassword"
			value={service.wordpress.mysqlRootUserPassword}
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="mysqlUser">User</label>
	<div class="col-span-2 ">
		<input name="mysqlUser" id="mysqlUser" value={service.wordpress.mysqlUser} disabled readonly />
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="mysqlPassword">Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			id="mysqlPassword"
			isPasswordField
			readonly
			disabled
			name="mysqlPassword"
			value={service.wordpress.mysqlPassword}
		/>
	</div>
</div>
