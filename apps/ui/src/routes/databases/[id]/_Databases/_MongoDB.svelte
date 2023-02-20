<script lang="ts">
	export let database: any;
	import { status } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import Explainer from '$lib/components/Explainer.svelte';
</script>

<div class="flex space-x-1 py-5 font-bold">
	<h1 class="title">MongoDB</h1>
</div>
<div class="space-y-2 lg:px-10 px-2">
	<div class="grid grid-cols-2 items-center">
		<label for="rootUser">{$t('forms.root_user')}
		<Explainer explanation="Can only be modified when the database is not active." /></label
	>
		<CopyPasswordField
			placeholder={$t('forms.generated_automatically_after_start')}
			id="rootUser"
			readonly={$status.database.isRunning}
			disabled={$status.database.isRunning}
			name="rootUser"
			bind:value={database.rootUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="rootUserPassword"
			>{$t('forms.roots_password')}
			<Explainer explanation="Can be modified even when the database is active." /></label
		>
		<CopyPasswordField
			disabled={false}
			readonly={false}
			placeholder={$t('forms.generated_automatically_after_start')}
			isPasswordField={true}
			id="rootUserPassword"
			name="rootUserPassword"
			bind:value={database.rootUserPassword}
		/>
	</div>
</div>
