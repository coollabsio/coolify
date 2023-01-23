<script lang="ts">
	export let database: any;
	import { status, appSession } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<h1 class="title">PostgreSQL</h1>
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
	{#if !$appSession.isARM}
		<div class="grid grid-cols-2 items-center">
			<label for="rootUser"
				>Postgres User Password <Explainer
					explanation="Could be changed while the database is running."
				/></label
			>
			<CopyPasswordField
				disabled={!$status.database.isRunning}
				readonly={!$status.database.isRunning}
				placeholder="Generated automatically after start"
				isPasswordField
				id="rootUserPassword"
				name="rootUserPassword"
				bind:value={database.rootUserPassword}
			/>
		</div>
	{/if}
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
		<label for="dbUserPassword"
			>Password
			<Explainer explanation="Could be changed while the database is running." /></label
		>
		<CopyPasswordField
			disabled={!$status.database.isRunning}
			readonly={!$status.database.isRunning}
			placeholder="Generated automatically after start"
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			bind:value={database.dbUserPassword}
		/>
	</div>
</div>
