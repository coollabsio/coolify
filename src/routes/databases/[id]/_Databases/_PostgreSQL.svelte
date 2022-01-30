<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="mr-4 text-xl tracking-tight">PostgreSQL</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="defaultDatabase">Default Database</label>
	<div class="col-span-2 ">
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
</div>
<div class="grid grid-cols-3 items-center">
	<label for="dbUser">User</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			readonly
			disabled
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
			readonly
			disabled
			placeholder="Generated automatically after start"
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
</div>
