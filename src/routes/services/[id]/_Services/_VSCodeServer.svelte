<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="font-bold flex space-x-1 py-5">
	<div class="text-xl tracking-tight mr-4">VSCode Server</div>
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
			value={service.vscodeserver.password}
		/>
	</div>
</div>
