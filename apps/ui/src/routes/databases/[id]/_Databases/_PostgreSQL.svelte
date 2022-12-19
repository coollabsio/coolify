<script lang="ts">
	export let database: any;
	import { status, appSession } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<h1 class="title">PostgreSQL</h1>
</div>
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="defaultDatabase">{$t('database.default_database')}</label>
		<CopyPasswordField
			required
			readonly={database.defaultDatabase}
			disabled={database.defaultDatabase}
			placeholder="{$t('forms.eg')}: mydb"
			id="defaultDatabase"
			name="defaultDatabase"
			bind:value={database.defaultDatabase}
		/>
	</div>
	{#if !$appSession.isARM}
		<div class="grid grid-cols-2 items-center">
			<label for="rootUser"
				>Postgres User Password <Explainer
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
	{/if}
	<div class="grid grid-cols-2 items-center">
		<label for="dbUser">{$t('forms.user')}</label>
		<CopyPasswordField
			readonly
			disabled
			placeholder={$t('forms.generated_automatically_after_start')}
			id="dbUser"
			name="dbUser"
			value={database.dbUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="dbUserPassword"
			>{$t('forms.password')}
			<Explainer explanation="Could be changed while the database is running." /></label
		>
		<CopyPasswordField
			disabled={!$status.database.isRunning}
			readonly={!$status.database.isRunning}
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField
			id="dbUserPassword"
			name="dbUserPassword"
			bind:value={database.dbUserPassword}
		/>
	</div>
</div>
