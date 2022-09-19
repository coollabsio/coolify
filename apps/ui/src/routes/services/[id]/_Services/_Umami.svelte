<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Umami</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="adminUser">Admin User</label>
	<input name="adminUser" id="adminUser" placeholder="admin" value="admin" disabled readonly />
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="umamiAdminPassword"
		>Initial Admin Password <Explainer
			explanation="It could be changed in Umami. <br>This is just the password set initially after the first start."
		/></label
	>
	<CopyPasswordField
		isPasswordField
		name="umamiAdminPassword"
		id="umamiAdminPassword"
		placeholder="admin"
		value={service.umami.umamiAdminPassword}
		disabled
		readonly
	/>
</div>
