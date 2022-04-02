<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let service;
	export let readOnly;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Plausible Analytics</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="email">Email Address</label>
	<input
		name="email"
		id="email"
		disabled={readOnly}
		readonly={readOnly}
		placeholder="Email address"
		bind:value={service.plausibleAnalytics.email}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="username">Username</label>
	<CopyPasswordField
		name="username"
		id="username"
		disabled={readOnly}
		readonly={readOnly}
		placeholder="User to login"
		bind:value={service.plausibleAnalytics.username}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="password">Password</label>
	<CopyPasswordField
		id="password"
		isPasswordField
		readonly
		disabled
		name="password"
		value={service.plausibleAnalytics.password}
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlUser">Username</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.plausibleAnalytics.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPassword">Password</label>
	<CopyPasswordField
		id="postgresqlPassword"
		isPasswordField
		readonly
		disabled
		name="postgresqlPassword"
		value={service.plausibleAnalytics.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlDatabase">Database</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.plausibleAnalytics.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
<!-- <div class="grid grid-cols-3 items-center">
	<label for="postgresqlPublicPort">Public Port</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="Generated automatically after start"
			readonly
			disabled
			id="postgresqlPublicPort"
			name="postgresqlPublicPort"
			value={service.plausibleAnalytics.postgresqlPublicPort}
		/>
	</div>
</div> -->
