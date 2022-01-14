<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<!-- <div class="grid grid-cols-3 items-center pb-8">
	<label for="defaultDatabase">Default Database Name</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="generate automatically"
			id="defaultDatabase"
			name="defaultDatabase"
			value={database.defaultDatabase}
		/>
	</div>
</div> -->
<!-- <div class="grid grid-cols-3 items-center">
	<label for="dbUser">User</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="generate automatically"
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
			placeholder="generate automatically"
			isPasswordField={true}
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
</div> -->
<div class="grid grid-cols-3 items-center">
	<label for="rootUser">Root User</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="generate automatically"
			id="rootUser"
			name="rootUser"
			value={database.rootUser}
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center pb-8">
	<label for="rootUserPassword">Root User's Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="generate automatically"
			isPasswordField={true}
			id="rootUserPassword"
			name="rootUserPassword"
			value={database.rootUserPassword}
		/>
	</div>
</div>
