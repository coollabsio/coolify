<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Redis</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword">Password</label>
		<CopyPasswordField
			disabled
			readonly
			placeholder="Generated automatically after start"
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
	<!-- <div class="grid grid-cols-3 items-center">
		<label for="rootUser">Root User</label>
		<div class="col-span-2 ">
			<CopyPasswordField
				disabled
				readonly
				placeholder="Generated automatically after start"
				id="rootUser"
				name="rootUser"
				value={database.rootUser}
			/>
		</div>
	</div>
	<div class="grid grid-cols-3 items-center">
		<label for="rootUserPassword">Root's Password</label>
		<div class="col-span-2 ">
			<CopyPasswordField
				disabled
				readonly
				placeholder="Generated automatically after start"
				isPasswordField
				id="rootUserPassword"
				name="rootUserPassword"
				value={database.rootUserPassword}
			/>
		</div>
	</div> -->
</div>
