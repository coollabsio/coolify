<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">VSCode Server</div>
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
