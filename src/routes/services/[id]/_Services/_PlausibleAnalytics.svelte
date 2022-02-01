<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	export let service;
	export let readOnly;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="mr-4 text-xl tracking-tight">Plausible Analytics</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="email">Email Address</label>
	<div class="col-span-2 ">
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
</div>
<div class="grid grid-cols-3 items-center">
	<label for="username">Username</label>
	<div class="col-span-2 ">
		<input
			name="username"
			id="username"
			disabled={readOnly}
			readonly={readOnly}
			placeholder="User to login"
			bind:value={service.plausibleAnalytics.username}
			required
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="password">Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			id="password"
			isPasswordField
			readonly
			disabled
			name="password"
			value={service.plausibleAnalytics.password}
		/>
	</div>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="mr-4 text-xl tracking-tight">PostgreSQL</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlUser">Username</label>
	<div class="col-span-2 ">
		<input
			name="postgresqlUser"
			id="postgresqlUser"
			value={service.plausibleAnalytics.postgresqlUser}
			readonly
			disabled
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlPassword">Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			id="postgresqlPassword"
			isPasswordField
			readonly
			disabled
			name="postgresqlPassword"
			value={service.plausibleAnalytics.postgresqlPassword}
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlDatabase">Database</label>
	<div class="col-span-2 ">
		<input
			name="postgresqlDatabase"
			id="postgresqlDatabase"
			value={service.plausibleAnalytics.postgresqlDatabase}
			readonly
			disabled
		/>
	</div>
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
