<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	import Select from 'svelte-select';
	export let service: any;
	export let readOnly: any;

	let mailgunRegions = [
		{
			value: 'EU',
			label: 'EU'
		},
		{
			value: 'US',
			label: 'US'
		}
	];
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Fider</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="jwtSecret">JWT Secret</label>
	<CopyPasswordField
		name="jwtSecret"
		id="jwtSecret"
		isPasswordField
		value={service.fider.jwtSecret}
		readonly
		disabled
	/>
</div>

<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailNoreply">Noreply Email</label>
	<input 
		class="w-full"
		name="emailNoreply"
		id="emailNoreply"
		type="email"
		required
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailNoreply}
		placeholder="{$t('forms.eg')}: noreply@yourdomain.com"
	/>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Email</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailMailgunApiKey">Mailgun API Key</label>
	<CopyPasswordField
		name="emailMailgunApiKey"
		id="emailMailgunApiKey"
		isPasswordField
		bind:value={service.fider.emailMailgunApiKey}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: key-yourkeygoeshere"
	/>
</div>

<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailMailgunDomain">Mailgun Domain</label>
	<input 
		class="w-full"
		name="emailMailgunDomain"
		id="emailMailgunDomain"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailMailgunDomain}
		placeholder="{$t('forms.eg')}: yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailMailgunRegion">Mailgun Region</label>
	<div class="custom-select-wrapper">
		<Select
			id="baseBuildImages"
			items={mailgunRegions}
			showIndicator
			on:select={(event) => (service.fider.emailMailgunRegion = event.detail.value)}
			value={service.fider.emailMailgunRegion || 'EU'}
			isClearable={false}
		/>
	</div>
</div>

<div class="flex space-x-1 py-5 lg:px-10 font-bold">
	<div class="text-lg">Or</div>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailSmtpHost">SMTP Host</label>
	<input 
		class="w-full"
		name="emailSmtpHost"
		id="emailSmtpHost"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailSmtpHost}
		placeholder="{$t('forms.eg')}: smtp.yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailSmtpPort">SMTP Port</label>
	<input 
		class="w-full"
		name="emailSmtpPort"
		id="emailSmtpPort"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailSmtpPort}
		placeholder="{$t('forms.eg')}: 587"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailSmtpUser">SMTP User</label>
	<input 
		class="w-full"
		name="emailSmtpUser"
		id="emailSmtpUser"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailSmtpUser}
		placeholder="{$t('forms.eg')}: user@yourdomain.com"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailSmtpPassword">SMTP Password</label>
	<CopyPasswordField
		name="emailSmtpPassword"
		id="emailSmtpPassword"
		isPasswordField
		bind:value={service.fider.emailSmtpPassword}
		readonly={readOnly}
		disabled={readOnly}
		placeholder="{$t('forms.eg')}: s0m3p4ssw0rd"
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="emailSmtpEnableStartTls">SMTP Start TLS</label>
	<input 
		class="w-full"
		name="emailSmtpEnableStartTls"
		id="emailSmtpEnableStartTls"
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.fider.emailSmtpEnableStartTls}
		placeholder="{$t('forms.eg')}: true"
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
		value={service.fider.postgresqlUser}
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
		value={service.fider.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center lg:px-10">
	<label class="text-base font-bold text-stone-100" for="postgresqlDatabase">{$t('index.database')}</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.fider.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
