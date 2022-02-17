<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">VSCode Server</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="password">Password</label>
	<CopyPasswordField
		id="password"
		isPasswordField
		readonly
		disabled
		name="password"
		value={service.vscodeserver.password}
	/>
</div>
