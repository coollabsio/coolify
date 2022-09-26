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
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">{$t('database.default_database')}</label>
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
		<label for="rootUser">{$t('forms.root_user')}</label>
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
		<label for="rootUser"
			>Root Password <Explainer
				explanation="Could be changed while the database is running."
			/></label
		>
		<CopyPasswordField
			readonly
			disabled
			placeholder="Generated automatically after start"
			isPasswordField
			id="rootUserPassword"
			name="rootUserPassword"
			value={database.rootUserPassword}
		/>
	</div>
</div>
