<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MinIO Server</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rootUser">Root User</label>
	<input
		name="rootUser"
		id="rootUser"
		placeholder="User to login"
		value={service.minio.rootUser}
		disabled
		readonly
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="rootUserPassword">Root's Password</label>
	<CopyPasswordField
		id="rootUserPassword"
		isPasswordField
		readonly
		disabled
		name="rootUserPassword"
		value={service.minio.rootUserPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="publicPort">API Port</label>
	<input
		name="publicPort"
		id="publicPort"
		value={service.minio.publicPort}
		disabled
		readonly
		placeholder="Generated automatically after start"
	/>
</div>
