<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">CouchDB</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">Default Database</label>
		<CopyPasswordField
			required
			readonly={database.defaultDatabase}
			disabled={database.defaultDatabase}
			placeholder="eg: mydb"
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
		<label for="rootUserPassword">Root's Password</label>
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
