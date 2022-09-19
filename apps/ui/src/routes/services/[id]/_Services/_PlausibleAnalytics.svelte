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
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="scriptName"
		>Script Name <Explainer
			explanation="Useful if you would like to rename the collector script to prevent it blocked by AdBlockers."
		/></label
	>
	<input 
		class="w-full"
		name="scriptName"
		id="scriptName"
		readonly={!$appSession.isAdmin && !$status.service.isRunning}
		disabled={!$appSession.isAdmin || $status.service.isRunning}
		placeholder="plausible.js"
		bind:value={service.plausibleAnalytics.scriptName}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="email">{$t('forms.email')}</label>
	<input 
		class="w-full"
		name="email"
		id="email"
		disabled={readOnly}
		readonly={readOnly}
		placeholder={$t('forms.email')}
		bind:value={service.plausibleAnalytics.email}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="username">{$t('forms.username')}</label>
	<CopyPasswordField
		name="username"
		id="username"
		disabled={readOnly}
		readonly={readOnly}
		placeholder={$t('forms.username')}
		bind:value={service.plausibleAnalytics.username}
		required
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="password">{$t('forms.password')}</label>
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
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlUser">{$t('forms.username')}</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.plausibleAnalytics.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="postgresqlPassword"
		isPasswordField
		readonly
		disabled
		name="postgresqlPassword"
		value={service.plausibleAnalytics.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlDatabase">{$t('index.database')}</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.plausibleAnalytics.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
