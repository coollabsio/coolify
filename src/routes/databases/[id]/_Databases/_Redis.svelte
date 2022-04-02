<script>
	export let database;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Redis</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword" class="text-base font-bold text-stone-100"
			>{$t('forms.password')}</label
		>
		<CopyPasswordField
			disabled
			readonly
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			value={database.dbUserPassword}
		/>
	</div>
	<!-- <div class="grid grid-cols-3 items-center">
		<label for="rootUser">{ $t('forms.root_user') }</label>
		<div class="col-span-2 ">
			<CopyPasswordField
				disabled
				readonly
				placeholder="{ $t('forms.generated_automatically_after_start') }"
				id="rootUser"
				name="rootUser"
				value={database.rootUser}
			/>
		</div>
	</div>
	<div class="grid grid-cols-3 items-center">
		<label for="rootUserPassword">{ $t('forms.roots_password') }</label>
		<div class="col-span-2 ">
			<CopyPasswordField
				disabled
				readonly
				placeholder="{ $t('forms.generated_automatically_after_start') }"
				isPasswordField
				id="rootUserPassword"
				name="rootUserPassword"
				value={database.rootUserPassword}
			/>
		</div>
	</div> -->
</div>
