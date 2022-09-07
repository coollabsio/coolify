<script lang="ts">
	export let database: any;
	import { status } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">EdgeDB</div>
</div>
<div class="space-y-2 px-10">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase" class="text-base font-bold text-stone-100"
			>{$t('database.default_database')}</label
		>
		<CopyPasswordField
			required
			readonly={database.defaultDatabase}
			disabled={database.defaultDatabase}
			placeholder="{$t('forms.eg')}: edgedb"
			id="defaultDatabase"
			name="defaultDatabase"
			bind:value={database.defaultDatabase}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser" class="text-base font-bold text-stone-100">{$t('forms.root_user')}</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder={$t('forms.generated_automatically_after_start')}
			id="rootUser"
			name="rootUser"
			value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser" class="text-base font-bold text-stone-100"
			>Root Password <Explainer
				explanation="Could be changed while the database is running."
			/></label
		>
		<CopyPasswordField
			disabled={!$status.database.isRunning}
			readonly={!$status.database.isRunning}
			placeholder="Generated automatically after start"
			isPasswordField
			id="rootUserPassword"
			name="rootUserPassword"
			bind:value={database.rootUserPassword}
		/>
	</div>
</div>
