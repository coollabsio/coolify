<script lang="ts">
	export let database: any;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<h1 class="title">CouchDB</h1>
</div>
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">Default Database</label>
		<CopyPasswordField
			required
			readonly={database.defaultDatabase}
			disabled={database.defaultDatabase}
			placeholder="Example: mydb"
			id="defaultDatabase"
			name="defaultDatabase"
			bind:value={database.defaultDatabase}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="dbUser">User</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder="Generated automatically after start"
			id="dbUser"
			name="dbUser"
			value={database.dbUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword">Password</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder="Generated automatically after start"
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser">Root User</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder="Generated automatically after start"
			id="rootUser"
			name="rootUser"
			value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUserPassword">Root Password</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder="Generated automatically after start"
			isPasswordField
			id="rootUserPassword"
			name="rootUserPassword"
			value={database.rootUserPassword}
		/>
	</div>
</div>
