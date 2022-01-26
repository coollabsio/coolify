<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="mr-4 text-xl tracking-tight">MinIO Server</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="rootUser">Root User</label>
	<div class="col-span-2 ">
		<input
			name="rootUser"
			id="rootUser"
			placeholder="User to login"
			value={service.minio.rootUser}
			disabled
			readonly />
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="rootUserPassword">Root's Password</label>
	<div class="col-span-2 ">
		<CopyPasswordField
			id="rootUserPassword"
			isPasswordField
			readonly
			disabled
			name="rootUserPassword"
			value={service.minio.rootUserPassword} />
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="publicPort">API Port</label>
	<div class="col-span-2 ">
		<input
			name="publicPort"
			id="publicPort"
			value={service.minio.publicPort}
			disabled
			readonly
			placeholder="Generated automatically after start" />
	</div>
</div>
