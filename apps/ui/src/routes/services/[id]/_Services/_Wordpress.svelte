<script lang="ts">
	import { post } from '$lib/api';
	import { page } from '$app/stores';
	import { status } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { browser } from '$app/env';
	import { t } from '$lib/translations';
	import { errorNotification, getDomain } from '$lib/common';

	export let service: any;
	export let readOnly: any;
	export let settings: any;
	const { id } = $page.params;

	let ftpUrl = generateUrl(service.wordpress.ftpPublicPort);
	let ftpUser = service.wordpress.ftpUser;
	let ftpPassword = service.wordpress.ftpPassword;
	let ftpLoading = false;
	let ownMysql = service.wordpress.ownMysql;

	function generateUrl(publicPort: any) {
		return browser
			? `sftp://${
					settings.fqdn ? getDomain(settings.fqdn) : window.location.hostname
			  }:${publicPort}`
			: 'Loading...';
	}
	async function changeSettings(name: any) {
		if (ftpLoading) return;
		if ($status.service.isRunning) {
			ftpLoading = true;
			let ftpEnabled = service.wordpress.ftpEnabled;

			if (name === 'ftpEnabled') {
				ftpEnabled = !ftpEnabled;
			}
			try {
				const {
					publicPort,
					ftpUser: user,
					ftpPassword: password
				} = await post(`/services/${id}/wordpress/ftp`, {
					ftpEnabled
				});
				ftpUrl = generateUrl(publicPort);
				ftpUser = user;
				ftpPassword = password;
				service.wordpress.ftpEnabled = ftpEnabled;
			} catch (error) {
				return errorNotification(error);
			} finally {
				ftpLoading = false;
			}
		} else {
			try {
				if (name === 'ownMysql') {
					ownMysql = !ownMysql;
				}
				await post(`/services/${id}/wordpress/settings`, {
					ownMysql
				});
				service.wordpress.ownMysql = ownMysql;
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Wordpress</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="extraConfig">{$t('forms.extra_config')}</label>
	<textarea
		bind:value={service.wordpress.extraConfig}
		disabled={$status.service.isRunning || $status.service.initialLoading}
		readonly={$status.service.isRunning}
		class:resize-none={$status.service.isRunning}
		rows="5"
		name="extraConfig"
		id="extraConfig"
		placeholder={!$status.service.isRunning && !$status.service.initialLoading
			? `${$t('forms.eg')}:

define('WP_ALLOW_MULTISITE', true);
define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);`
			: 'N/A'}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<Setting
		bind:setting={service.wordpress.ftpEnabled}
		loading={ftpLoading}
		disabled={!$status.service.isRunning}
		on:click={() => changeSettings('ftpEnabled')}
		title="Enable sFTP connection to WordPress data"
		description="Enables an on-demand sFTP connection to the WordPress data directory. This is useful if you want to use sFTP to upload files."
	/>
</div>
{#if service.wordpress.ftpEnabled}
	<div class="grid grid-cols-2 items-center px-10">
		<label for="ftpUrl">sFTP Connection URI</label>
		<CopyPasswordField id="ftpUrl" readonly disabled name="ftpUrl" value={ftpUrl} />
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="ftpUser">User</label>
		<CopyPasswordField id="ftpUser" readonly disabled name="ftpUser" value={ftpUser} />
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="ftpPassword">Password</label>
		<CopyPasswordField
			id="ftpPassword"
			isPasswordField
			readonly
			disabled
			name="ftpPassword"
			value={ftpPassword}
		/>
	</div>
{/if}
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MySQL</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<Setting
		dataTooltip={$t('forms.must_be_stopped_to_modify')}
		bind:setting={service.wordpress.ownMysql}
		disabled={$status.service.isRunning}
		on:click={() => !$status.service.isRunning && changeSettings('ownMysql')}
		title="Use your own MySQL server"
		description="Enables the use of your own MySQL server. If you don't have one, you can use the one provided by Coolify."
	/>
</div>
{#if service.wordpress.ownMysql}
	<div class="grid grid-cols-2 items-center px-10">
		<label for="mysqlHost">Host</label>
		<input
			name="mysqlHost"
			id="mysqlHost"
			required
			readonly={$status.service.isRunning}
			disabled={$status.service.isRunning}
			bind:value={service.wordpress.mysqlHost}
			placeholder="{$t('forms.eg')}: db.coolify.io"
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="mysqlPort">Port</label>
		<input
			name="mysqlPort"
			id="mysqlPort"
			required
			readonly={$status.service.isRunning}
			disabled={$status.service.isRunning}
			bind:value={service.wordpress.mysqlPort}
			placeholder="{$t('forms.eg')}: 3306"
		/>
	</div>
{/if}
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlDatabase">{$t('index.database')}</label>
	<input
		name="mysqlDatabase"
		id="mysqlDatabase"
		required
		readonly={readOnly && !service.wordpress.ownMysql}
		disabled={readOnly && !service.wordpress.ownMysql}
		bind:value={service.wordpress.mysqlDatabase}
		placeholder="{$t('forms.eg')}: wordpress_db"
	/>
</div>
{#if !service.wordpress.ownMysql}
	<div class="grid grid-cols-2 items-center px-10">
		<label for="mysqlRootUser">{$t('forms.root_user')}</label>
		<input
			name="mysqlRootUser"
			id="mysqlRootUser"
			placeholder="MySQL {$t('forms.root_user')}"
			value={service.wordpress.mysqlRootUser}
			readonly={$status.service.isRunning || !service.wordpress.ownMysql}
			disabled={$status.service.isRunning || !service.wordpress.ownMysql}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="mysqlRootUserPassword">{$t('forms.roots_password')}</label>
		<CopyPasswordField
			id="mysqlRootUserPassword"
			isPasswordField
			readonly={$status.service.isRunning || !service.wordpress.ownMysql}
			disabled={$status.service.isRunning || !service.wordpress.ownMysql}
			name="mysqlRootUserPassword"
			value={service.wordpress.mysqlRootUserPassword}
		/>
	</div>
{/if}
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlUser">{$t('forms.user')}</label>
	<input
		name="mysqlUser"
		id="mysqlUser"
		bind:value={service.wordpress.mysqlUser}
		readonly={$status.service.isRunning || !service.wordpress.ownMysql}
		disabled={$status.service.isRunning || !service.wordpress.ownMysql}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="mysqlPassword"
		isPasswordField
		readonly={$status.service.isRunning || !service.wordpress.ownMysql}
		disabled={$status.service.isRunning || !service.wordpress.ownMysql}
		name="mysqlPassword"
		bind:value={service.wordpress.mysqlPassword}
	/>
</div>
