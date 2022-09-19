<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { appSession, status } from '$lib/store';
	import { t } from '$lib/translations';
	export let service: any;
	export let readOnly: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Plausible Analytics</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="scriptName"
		>Script Name <Explainer
			explanation="Useful if you would like to rename the collector script to prevent it blocked by AdBlockers."
		/></label
	>
	<input
		name="scriptName"
		id="scriptName"
		readonly={!$appSession.isAdmin && !$status.service.isRunning}
		disabled={!$appSession.isAdmin ||
			$status.service.isRunning ||
			$status.service.initialLoading}
		placeholder="plausible.js"
		bind:value={service.plausibleAnalytics.scriptName}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="email">{$t('forms.email')}</label>
	<input
		name="email"
		id="email"
		disabled={!$appSession.isAdmin ||
			$status.service.isRunning ||
			$status.service.initialLoading}
		readonly={readOnly}
		placeholder={$t('forms.email')}
		bind:value={service.plausibleAnalytics.email}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="username">{$t('forms.username')}</label>
	<CopyPasswordField
		name="username"
		id="username"
		disabled={!$appSession.isAdmin ||
			$status.service.isRunning ||
			$status.service.initialLoading}
		readonly={readOnly}
		placeholder={$t('forms.username')}
		bind:value={service.plausibleAnalytics.username}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="password">{$t('forms.password')}</label>
	<CopyPasswordField
		id="password"
		isPasswordField
		readonly
		disabled
		name="password"
		value={service.plausibleAnalytics.password}
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlUser">{$t('forms.username')}</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.plausibleAnalytics.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="postgresqlPassword"
		isPasswordField
		readonly
		disabled
		name="postgresqlPassword"
		value={service.plausibleAnalytics.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlDatabase">{$t('index.database')}</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.plausibleAnalytics.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
