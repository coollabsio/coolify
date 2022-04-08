<script lang="ts">
	import { post } from '$lib/api';
	import { page } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { browser } from '$app/env';
	import { getDomain } from '$lib/components/common';

	export let service;
	export let isRunning;
	export let readOnly;
	export let settings;
	const { id } = $page.params;

	let ftpUrl = generateUrl(service.wordpress.ftpPublicPort);
	let ftpUser = service.wordpress.ftpUser;
	let ftpPassword = service.wordpress.ftpPassword;
	let ftpLoading = false;

	function generateUrl(publicPort) {
		return browser
			? `sftp://${
					settings.fqdn ? getDomain(settings.fqdn) : window.location.hostname
			  }:${publicPort}`
			: 'Loading...';
	}
	async function changeSettings(name) {
		if (ftpLoading) return;
		if (isRunning) {
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
				} = await post(`/services/${id}/wordpress/settings.json`, {
					ftpEnabled
				});
				ftpUrl = generateUrl(publicPort);
				ftpUser = user;
				ftpPassword = password;
				service.wordpress.ftpEnabled = ftpEnabled;
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				ftpLoading = false;
			}
		}
	}
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Wordpress</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="extraConfig">Extra Config</label>
	<textarea
		disabled={isRunning}
		readonly={isRunning}
		class:resize-none={isRunning}
		rows={isRunning ? 1 : 5}
		name="extraConfig"
		id="extraConfig"
		placeholder={!isRunning
			? `eg:

define('WP_ALLOW_MULTISITE', true);
define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);`
			: 'N/A'}>{service.wordpress.extraConfig}</textarea
	>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<Setting
		bind:setting={service.wordpress.ftpEnabled}
		loading={ftpLoading}
		disabled={!isRunning}
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
		<CopyPasswordField id="ftpPassword" readonly disabled name="ftpPassword" value={ftpPassword} />
	</div>
{/if}
<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MySQL</div>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlDatabase">Database</label>
	<input
		name="mysqlDatabase"
		id="mysqlDatabase"
		required
		readonly={readOnly}
		disabled={readOnly}
		bind:value={service.wordpress.mysqlDatabase}
		placeholder="eg: wordpress_db"
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlRootUser">Root User</label>
	<input
		name="mysqlRootUser"
		id="mysqlRootUser"
		placeholder="MySQL Root User"
		value={service.wordpress.mysqlRootUser}
		disabled
		readonly
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlRootUserPassword">Root's Password</label>
	<CopyPasswordField
		id="mysqlRootUserPassword"
		isPasswordField
		readonly
		disabled
		name="mysqlRootUserPassword"
		value={service.wordpress.mysqlRootUserPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlUser">User</label>
	<input name="mysqlUser" id="mysqlUser" value={service.wordpress.mysqlUser} disabled readonly />
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="mysqlPassword">Password</label>
	<CopyPasswordField
		id="mysqlPassword"
		isPasswordField
		readonly
		disabled
		name="mysqlPassword"
		value={service.wordpress.mysqlPassword}
	/>
</div>
