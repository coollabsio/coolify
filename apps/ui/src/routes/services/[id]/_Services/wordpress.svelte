<script lang="ts">
	import { post } from '$lib/api';
	import { page } from '$app/stores';
	import { status } from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { browser } from '$app/env';
	import { errorNotification, getDomain } from '$lib/common';

	export let service: any;
	const { id } = $page.params;
	const settings = service.settings;
	const { ipv4, ipv6 } = settings;

	let ftpUrl = generateUrl(service.wordpress?.ftpPublicPort) || '';
	let ftpUser = service.wordpress?.ftpUser;
	let ftpPassword = service.wordpress?.ftpPassword;
	let ftpLoading = false;
	let ftpEnabled = service.wordpress?.ftpEnabled || false;

	function generateUrl(publicPort: any) {
		return browser
			? `sftp://${settings?.fqdn ? getDomain(settings.fqdn) : ipv4 || ipv6}:${publicPort}`
			: 'Loading...';
	}
	async function changeSettings(name: any) {
		if (ftpLoading) return;
		if ($status.service.overallStatus === 'healthy') {
			ftpLoading = true;
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
		}
	}
</script>

<div class="grid grid-cols-2 items-center">
	<Setting
		id="ftpEnabled"
		bind:setting={ftpEnabled}
		loading={ftpLoading}
		disabled={$status.service.overallStatus !== 'healthy'}
		on:click={() => changeSettings('ftpEnabled')}
		title="Enable sFTP connection to WordPress data"
		description="Enables an on-demand sFTP connection to the WordPress data directory. This is useful if you want to use sFTP to upload files."
	/>
</div>
{#if service.wordpress?.ftpEnabled}
	<div class="grid grid-cols-2 items-center">
		<label for="ftpUrl">sFTP Connection URI</label>
		<CopyPasswordField id="ftpUrl" readonly disabled name="ftpUrl" value={ftpUrl} />
	</div>
	<div class="grid grid-cols-2 items-center">
		<label for="ftpUser">User</label>
		<CopyPasswordField id="ftpUser" readonly disabled name="ftpUser" value={ftpUser} />
	</div>
	<div class="grid grid-cols-2 items-center">
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
