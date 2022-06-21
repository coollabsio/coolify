<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service;
	export let readOnly;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Cal.com</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="licenseKey">License Key</label>
	<CopyPasswordField
		name="licenseKey"
		id="licenseKey"
		isPasswordField
		bind:value={service.calcom.licenseKey}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: XXXXXXXXXXXXXXXXXX"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="msGraphClientId">Microsoft Graph Client ID</label>
	<input
		name="msGraphClientId"
		id="msGraphClientId"
		bind:value={service.calcom.msGraphClientId}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: XXXXXXXXXXXXXXXXXX"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="msGraphClientSecret">Microsoft Graph Client Secret</label>
	<CopyPasswordField
		name="msGraphClientSecret"
		id="msGraphClientSecret"
		isPasswordField
		bind:value={service.calcom.msGraphClientSecret}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: XXXXXXXXXXXXXXXXXX"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="zoomClientId">Zoom Client ID</label>
	<input
		name="zoomClientId"
		id="zoomClientId"
		bind:value={service.calcom.zoomClientId}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: XXXXXXXXXXXXXXXXXX"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="zoomClientSecret">Zoom Client Secret</label>
	<CopyPasswordField
		name="zoomClientSecret"
		id="zoomClientSecret"
		isPasswordField
		bind:value={service.calcom.zoomClientSecret}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: XXXXXXXXXXXXXXXXXX"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="googleApiCredentials">Google API Credential</label>
	<CopyPasswordField
		name="googleApiCredentials"
		id="googleApiCredentials"
		isPasswordField
		bind:value={service.calcom.googleApiCredentials}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: {'{'}{'}'}"
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Email</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="emailFrom">Email From</label>
	<input
		name="emailFrom"
		id="emailFrom"
		type="email"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.calcom.emailFrom}
		placeholder="{$t('forms.eg')}: noreply@yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpHost">SMTP Host</label>
	<input
		name="emailSmtpHost"
		id="emailSmtpHost"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.calcom.emailSmtpHost}
		placeholder="{$t('forms.eg')}: smtp.yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpPort">SMTP Port</label>
	<input
		name="emailSmtpPort"
		id="emailSmtpPort"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.calcom.emailSmtpPort}
		placeholder="{$t('forms.eg')}: 587"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpUser">SMTP User</label>
	<input
		name="emailSmtpUser"
		id="emailSmtpUser"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.calcom.emailSmtpUser}
		placeholder="{$t('forms.eg')}: user@yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="emailSmtpPassword">SMTP Password</label>
	<CopyPasswordField
		name="emailSmtpPassword"
		id="emailSmtpPassword"
		isPasswordField
		bind:value={service.calcom.emailSmtpPassword}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: s0m3p4ssw0rd"
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
		value={service.calcom.postgresqlUser}
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
		value={service.calcom.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlDatabase">{$t('index.database')}</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.calcom.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
