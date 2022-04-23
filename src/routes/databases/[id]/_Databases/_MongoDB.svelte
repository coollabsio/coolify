<script>
	export let database;
	export let isRunning;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { t } from '$lib/translations';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MongoDB</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser" class="text-base font-bold text-stone-100">{$t('forms.root_user')}</label>
		<CopyPasswordField
			placeholder={$t('forms.generated_automatically_after_start')}
			id="rootUser"
			readonly
			disabled
			name="rootUser"
			value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUserPassword" class="text-base font-bold text-stone-100"
			>{$t('forms.roots_password')}</label
		>
		<CopyPasswordField
			disabled={!isRunning}
			readonly={!isRunning}
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField={true}
			id="rootUserPassword"
			name="rootUserPassword"
			bind:value={database.rootUserPassword}
		/>
		<Explainer text="Could be changed while the database is running." />
	</div>
</div>
