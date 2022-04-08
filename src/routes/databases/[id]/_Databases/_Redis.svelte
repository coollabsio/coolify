<script>
	export let database;
	export let isRunning;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Redis</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword" class="text-base font-bold text-stone-100">Password</label>
		<CopyPasswordField
			disabled={!isRunning}
			readonly={!isRunning}
			placeholder="Generated automatically after start"
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			bind:value={database.dbUserPassword}
		/>
		<Explainer text="Could be changed while the database is running." />
	</div>
</div>
