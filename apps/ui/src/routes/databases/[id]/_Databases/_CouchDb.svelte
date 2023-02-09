<script lang="ts">
	export let database: any;
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import { status } from '$lib/store';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<h1 class="title">CouchDB</h1>
</div>
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">{$t('database.default_database')}
			<Explainer explanation="Can only be modified when the database is not active."/>
		</label>
		<CopyPasswordField
			required
			readonly={$status.database.isRunning}
			disabled={$status.database.isRunning}
			placeholder="{$t('forms.eg')}: mydb"
			id="defaultDatabase"
			name="defaultDatabase"
			bind:value={database.defaultDatabase}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="dbUser">{$t('forms.user')}
			<Explainer explanation="Can only be modified when the database is not active."/>
		</label>
		<CopyPasswordField
		readonly={$status.database.isRunning}
		disabled={$status.database.isRunning}
			placeholder={$t('forms.generated_automatically_after_start')}
			id="dbUser"
			name="dbUser"
			bind:value={database.dbUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword">{$t('forms.password')}
			<Explainer explanation="Can be modified even when the database is active." />
		</label>
		<CopyPasswordField
		readonly={false}
		disabled={false}
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			bind:value={database.dbUserPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser">{$t('forms.root_user')}
			<Explainer explanation="Can only be modified when the database is not active."/>
		</label>
		<CopyPasswordField
		readonly={$status.database.isRunning}
		disabled={$status.database.isRunning}
			placeholder={$t('forms.generated_automatically_after_start')}
			id="rootUser"
			name="rootUser"
			bind:value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUserPassword">{$t('forms.roots_password')}	
			<Explainer explanation="Can be modified even when the database is active." />
		</label>
		<CopyPasswordField
		readonly={false}
		disabled={false}
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField
			id="rootUserPassword"
			name="rootUserPassword"
			bind:value={database.rootUserPassword}
		/>
	</div>
</div>
