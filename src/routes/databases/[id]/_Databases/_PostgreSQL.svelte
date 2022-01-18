<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="grid grid-cols-3 items-center">
	<label for="defaultDatabase">Default Database</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="Generated automatically after start"
			id="defaultDatabase"
			name="defaultDatabase"
			value={database.defaultDatabase}
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="dbUser">User</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="Generated automatically after start"
			id="dbUser"
			name="dbUser"
			value={database.dbUser}
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="dbUserPassword">Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="Generated automatically after start"
			isPasswordField={true}
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
</div>
