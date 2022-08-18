<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { t } from '$lib/translations';
	export let service: any;
	function toggleEmailSmtpUseTls() {
		service.glitchTip.emailSmtpUseTls = !service.glitchTip.emailSmtpUseTls;
	}
	function toggleEmailSmtpUseSsl() {
		service.glitchTip.emailSmtpUseSsl = !service.glitchTip.emailSmtpUseSsl;
	}
	function toggleEnableOpenUserRegistration() {
		service.glitchTip.enableOpenUserRegistration = !service.glitchTip.enableOpenUserRegistration;
	}
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">GlitchTip</div>
</div>

<div class="flex space-x-1 py-2 font-bold">
	<div class="subtitle">Settings</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<Setting
		bind:setting={service.glitchTip.enableOpenUserRegistration}
		on:click={toggleEnableOpenUserRegistration}
		title={'Enable Open User Registration'}
		description={''}
	/>
</div>

<div class="flex space-x-1 py-2 font-bold">
	<div class="subtitle">Email settings</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultEmailFrom" class="text-base font-bold text-stone-100">Default Email From</label
	>
	<CopyPasswordField
		required
		name="defaultEmailFrom"
		id="defaultEmailFrom"
		value={service.glitchTip.defaultEmailFrom}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpHost" class="text-base font-bold text-stone-100">SMTP Host</label>
	<CopyPasswordField
		name="emailSmtpHost"
		id="emailSmtpHost"
		value={service.glitchTip.emailSmtpHost}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpPort" class="text-base font-bold text-stone-100">SMTP Port</label>
	<CopyPasswordField
		name="emailSmtpPort"
		id="emailSmtpPort"
		value={service.glitchTip.emailSmtpPort}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpUser" class="text-base font-bold text-stone-100">SMTP User</label>
	<CopyPasswordField
		name="emailSmtpUser"
		id="emailSmtpUser"
		value={service.glitchTip.emailSmtpUser}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpPassword" class="text-base font-bold text-stone-100">SMTP Password</label>
	<CopyPasswordField
		name="emailSmtpPassword"
		id="emailSmtpPassword"
		value={service.glitchTip.emailSmtpPassword}
		isPasswordField
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<Setting
		bind:setting={service.glitchTip.emailSmtpUseTls}
		on:click={toggleEmailSmtpUseTls}
		title={'SMTP Use TLS'}
		description={''}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<Setting
		bind:setting={service.glitchTip.emailSmtpUseSsl}
		on:click={toggleEmailSmtpUseSsl}
		title={'SMTP Use SSL'}
		description={''}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="emailBackend" class="text-base font-bold text-stone-100">Email Backend</label>
	<CopyPasswordField name="emailBackend" id="emailBackend" value={service.glitchTip.emailBackend} />
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="mailgunApiKey" class="text-base font-bold text-stone-100">Mailgun API Key</label>
	<CopyPasswordField
		name="mailgunApiKey"
		id="mailgunApiKey"
		value={service.glitchTip.mailgunApiKey}
	/>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="sendgridApiKey" class="text-base font-bold text-stone-100">SendGrid API Key</label>
	<CopyPasswordField
		name="sendgridApiKey"
		id="sendgridApiKey"
		value={service.glitchTip.sendgridApiKey}
	/>
</div>

<div class="flex space-x-1 py-2 font-bold">
	<div class="subtitle">Default User & Superuser</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultEmail" class="text-base font-bold text-stone-100">{$t('forms.email')}</label>
	<CopyPasswordField
		name="defaultEmail"
		id="defaultEmail"
		value={service.glitchTip.defaultEmail}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultUsername" class="text-base font-bold text-stone-100"
		>{$t('forms.username')}</label
	>
	<CopyPasswordField
		name="defaultUsername"
		id="defaultUsername"
		value={service.glitchTip.defaultUsername}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="defaultPassword" class="text-base font-bold text-stone-100"
		>{$t('forms.password')}</label
	>
	<CopyPasswordField
		name="defaultPassword"
		id="defaultPassword"
		value={service.glitchTip.defaultPassword}
		readonly
		disabled
		isPasswordField
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlUser" class="text-base font-bold text-stone-100"
		>{$t('forms.username')}</label
	>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.glitchTip.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPassword" class="text-base font-bold text-stone-100"
		>{$t('forms.password')}</label
	>
	<CopyPasswordField
		id="postgresqlPassword"
		isPasswordField
		readonly
		disabled
		name="postgresqlPassword"
		value={service.glitchTip.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlDatabase" class="text-base font-bold text-stone-100"
		>{$t('index.database')}</label
	>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.glitchTip.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
