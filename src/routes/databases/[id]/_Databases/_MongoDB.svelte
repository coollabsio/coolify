<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MongoDB</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser" class="text-base font-bold text-stone-100">Root User</label>
		<CopyPasswordField
			placeholder="Generated automatically after start"
			id="rootUser"
			readonly
			disabled
			name="rootUser"
			value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUserPassword" class="text-base font-bold text-stone-100">Root's Password</label>
		<CopyPasswordField
			placeholder="Generated automatically after start"
			isPasswordField={true}
			readonly
			disabled
			id="rootUserPassword"
			name="rootUserPassword"
			value={database.rootUserPassword}
		/>
	</div>
</div>
