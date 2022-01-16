<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="font-bold flex space-x-1 py-5">
	<div class="text-xl tracking-tight mr-4">Plausible</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="email">Email Address</label>
	<div class="col-span-2 ">
		<input
			name="email"
			id="email"
			placeholder="Email address"
			value={service.plausibleAnalytics.email}
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
			placeholder="User to login"
			value={service.plausibleAnalytics.username}
			required
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="password">Password</label>
	<div class="col-span-2 ">
		<input name="password" id="password" value={service.plausibleAnalytics.password} required />
	</div>
</div>
<div class="font-bold flex space-x-1 py-5">
	<div class="text-xl tracking-tight mr-4">PostgreSQL</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlUser">Username</label>
	<div class="col-span-2 ">
		<input
			name="postgresqlUser"
			id="postgresqlUser"
			value={service.plausibleAnalytics.postgresqlUser}
			required
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlPassword">Password</label>
	<div class="col-span-2 ">
		<input
			name="postgresqlPassword"
			id="postgresqlPassword"
			value={service.plausibleAnalytics.postgresqlPassword}
			required
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
			required
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="postgresqlPublicPort">Public Port</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			placeholder="Generated if database is public"
			isReadOnly={true}
			id="postgresqlPublicPort"
			name="postgresqlPublicPort"
			value={service.plausibleAnalytics.postgresqlPublicPort}
		/>
	</div>
</div>
