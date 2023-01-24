<script lang="ts">
	export let database: any;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">EdgeDB</div>
</div>
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">Default Database</label>
		<CopyPasswordField
			required
			readonly={database.defaultDatabase}
			disabled={database.defaultDatabase}
			placeholder="Example: edgedb"
			id="defaultDatabase"
			name="defaultDatabase"
			bind:value={database.defaultDatabase}
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
		<label for="rootUser"
			>Root Password <Explainer
				explanation="Could be changed while the database is running."
			/></label
		>
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
