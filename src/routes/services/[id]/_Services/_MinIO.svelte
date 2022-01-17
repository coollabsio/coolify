<script lang="ts">
	export let service;
</script>

<div class="grid grid-cols-3 items-center pt-5">
	<label for="publicPort">API Port</label>
	<div class="col-span-2 ">
		<input name="publicPort" id="publicPort" value={service.minio.publicPort}  readonly />
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="rootUser">Root User</label>
	<div class="col-span-2 ">
		<input
			name="rootUser"
			id="rootUser"
			placeholder="User to login"
			value={service.minio.rootUser}
			required
		/>
	</div>
</div>
<div class="grid grid-cols-3 items-center">
	<label for="rootUserPassword">Root User Password</label>
	<div class="col-span-2 ">
		<input name="rootUserPassword" id="rootUserPassword" value={service.minio.rootUserPassword} required />
	</div>
</div>