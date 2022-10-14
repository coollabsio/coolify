<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	export let service: any;
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Umami</div>
</div>
<div class="space-y-2 px-4">
	<div class="grid grid-cols-2 items-center ">
		<label for="adminUser">Admin User</label>
		<input
			class="w-full"
			name="adminUser"
			id="adminUser"
			placeholder="admin"
			value="admin"
			disabled
			readonly
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="umamiAdminPassword"
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
</div>
