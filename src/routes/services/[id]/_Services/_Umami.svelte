<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';

	export let service;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Umami</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="adminUser">Admin User</label>
	<input name="adminUser" id="adminUser" placeholder="admin" value="admin" disabled readonly />
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="umamiAdminPassword">Initial Admin Password</label>
	<CopyPasswordField
		isPasswordField
		name="umamiAdminPassword"
		id="umamiAdminPassword"
		placeholder="admin"
		value={service.umami.umamiAdminPassword}
		disabled
		readonly
	/>
	<Explainer
		text="It could be changed in Umami. <br>This is just the password set initially after the first start."
	/>
</div>
