<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { addToast, status } from '$lib/store';
	import Setting from '$lib/components/Setting.svelte';
	import { t } from '$lib/translations';
	import { post } from '$lib/api';
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/common';
	export let service: any;

	const { id } = $page.params;
	let loading = false;

	async function changeSettings(name: any) {
		if (loading || $status.service.isRunning) return;

		let enableOpenUserRegistration = service.glitchTip.enableOpenUserRegistration;
		let emailSmtpUseSsl = service.glitchTip.emailSmtpUseSsl;
		let emailSmtpUseTls = service.glitchTip.emailSmtpUseTls;

		loading = true;
		if (name === 'enableOpenUserRegistration') {
			enableOpenUserRegistration = !enableOpenUserRegistration;
		}
		if (name === 'emailSmtpUseSsl') {
			emailSmtpUseSsl = !emailSmtpUseSsl;
		}
		if (name === 'emailSmtpUseTls') {
			emailSmtpUseTls = !emailSmtpUseTls;
		}
		try {
			await post(`/services/${id}/glitchtip/settings`, {
				enableOpenUserRegistration,
				emailSmtpUseSsl,
				emailSmtpUseTls
			});
			service.glitchTip.emailSmtpUseTls = emailSmtpUseTls;
			service.glitchTip.emailSmtpUseSsl = emailSmtpUseSsl;
			service.glitchTip.enableOpenUserRegistration = enableOpenUserRegistration;
			return addToast({
				message: 'Settings updated.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">GlitchTip</div>
</div>

<div class="grid grid-cols-2 items-center lg:px-10 px-2">
	<Setting
		id="enableOpenUserRegistration"
		bind:setting={service.glitchTip.enableOpenUserRegistration}
		{loading}
		disabled={$status.service.isRunning}
		on:click={() => changeSettings('enableOpenUserRegistration')}
		title="Enable Open User Registration"
		description={''}
	/>
	<!-- <Setting
		bind:setting={service.glitchTip.enableOpenUserRegistration}
		on:click={toggleEnableOpenUserRegistration}
		title={'Enable Open User Registration'}
		description={''}
	/> -->
</div>

<div class="flex space-x-1 py-2 font-bold">
	<div class="subtitle">Email settings</div>
</div>
<div class="space-y-2">
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<Setting
			id="emailSmtpUseTls"
			bind:setting={service.glitchTip.emailSmtpUseTls}
			{loading}
			disabled={$status.service.isRunning}
			on:click={() => changeSettings('emailSmtpUseTls')}
			title="Use TLS for SMTP"
			description={''}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<Setting
			id="emailSmtpUseSsl"
			bind:setting={service.glitchTip.emailSmtpUseSsl}
			{loading}
			disabled={$status.service.isRunning}
			on:click={() => changeSettings('emailSmtpUseSsl')}
			title="Use SSL for SMTP"
			description={''}
		/>
	</div>
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="defaultEmailFrom">Default Email From</label>
		<CopyPasswordField
			required
			name="defaultEmailFrom"
			id="defaultEmailFrom"
			bind:value={service.glitchTip.defaultEmailFrom}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="emailSmtpHost">SMTP Host</label>
		<CopyPasswordField
			name="emailSmtpHost"
			id="emailSmtpHost"
			bind:value={service.glitchTip.emailSmtpHost}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="emailSmtpPort">SMTP Port</label>
		<CopyPasswordField
			name="emailSmtpPort"
			id="emailSmtpPort"
			bind:value={service.glitchTip.emailSmtpPort}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="emailSmtpUser">SMTP User</label>
		<CopyPasswordField
			name="emailSmtpUser"
			id="emailSmtpUser"
			bind:value={service.glitchTip.emailSmtpUser}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="emailSmtpPassword">SMTP Password</label>
		<CopyPasswordField
			name="emailSmtpPassword"
			id="emailSmtpPassword"
			bind:value={service.glitchTip.emailSmtpPassword}
			isPasswordField
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="emailBackend">Email Backend</label>
		<CopyPasswordField
			name="emailBackend"
			id="emailBackend"
			bind:value={service.glitchTip.emailBackend}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="mailgunApiKey">Mailgun API Key</label>
		<CopyPasswordField
			name="mailgunApiKey"
			id="mailgunApiKey"
			bind:value={service.glitchTip.mailgunApiKey}
		/>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="sendgridApiKey">SendGrid API Key</label>
		<CopyPasswordField
			name="sendgridApiKey"
			id="sendgridApiKey"
			bind:value={service.glitchTip.sendgridApiKey}
		/>
	</div>

	<div class="flex space-x-1 py-2 font-bold">
		<div class="subtitle">Default User & Superuser</div>
	</div>

	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="defaultEmail">{$t('forms.email')}</label>
		<CopyPasswordField
			name="defaultEmail"
			id="defaultEmail"
			bind:value={service.glitchTip.defaultEmail}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="defaultUsername">{$t('forms.username')}</label>
		<CopyPasswordField
			name="defaultUsername"
			id="defaultUsername"
			bind:value={service.glitchTip.defaultUsername}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="defaultPassword">{$t('forms.password')}</label>
		<CopyPasswordField
			name="defaultPassword"
			id="defaultPassword"
			bind:value={service.glitchTip.defaultPassword}
			readonly
			disabled
			isPasswordField
		/>
	</div>
</div>
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>
<div class="space-y-2">
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="postgresqlUser">{$t('forms.username')}</label>
		<CopyPasswordField
			name="postgresqlUser"
			id="postgresqlUser"
			bind:value={service.glitchTip.postgresqlUser}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="postgresqlPassword">{$t('forms.password')}</label>
		<CopyPasswordField
			id="postgresqlPassword"
			isPasswordField
			readonly
			disabled
			name="postgresqlPassword"
			bind:value={service.glitchTip.postgresqlPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center lg:px-10 px-2">
		<label for="postgresqlDatabase">{$t('index.database')}</label>
		<CopyPasswordField
			name="postgresqlDatabase"
			id="postgresqlDatabase"
			bind:value={service.glitchTip.postgresqlDatabase}
			readonly
			disabled
		/>
	</div>
</div>
