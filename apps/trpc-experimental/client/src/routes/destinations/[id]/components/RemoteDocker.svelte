<script lang="ts">
	export let destination: any;
	export let settings: any;

	import { page } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { onMount } from 'svelte';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession } from '$lib/store';

	const { id } = $page.params;

	let cannotDisable = settings.fqdn && destination.engine === '/var/run/docker.sock';
	let loading = {
		restart: false,
		proxy: true,
		save: false,
		verify: false
	};

	$: isDisabled = !$appSession.isAdmin;

	async function handleSubmit() {
		loading.save = true;
		try {
			await post(`/destinations/${id}`, { ...destination });
			addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
	onMount(async () => {
		if (destination.remoteEngine && destination.remoteVerified) {
			loading.proxy = true;
			const { isRunning } = await get(`/destinations/${id}/status`);
			if (isRunning === false && destination.isCoolifyProxyUsed === true) {
				destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
				try {
					await post(`/destinations/${id}/settings`, {
						isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
						engine: destination.engine
					});
					await stopProxy();
				} catch (error) {
					return errorNotification(error);
				}
			} else if (isRunning === true && destination.isCoolifyProxyUsed === false) {
				destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
				try {
					await post(`/destinations/${id}/settings`, {
						isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
						engine: destination.engine
					});
					await startProxy();
				} catch (error) {
					return errorNotification(error);
				}
			}
		}

		loading.proxy = false;
	});
	async function changeProxySetting() {
		if (!destination.remoteVerified) return;
		loading.proxy = true;
		if (!cannotDisable) {
			const isProxyActivated = destination.isCoolifyProxyUsed;
			if (isProxyActivated) {
				const sure = confirm(
					`Are you sure you want to ${
						destination.isCoolifyProxyUsed ? 'disable' : 'enable'
					} Coolify proxy? It will remove the proxy for all configured networks and all deployments! Nothing will be reachable if you do it!`
				);
				if (!sure) {
					loading.proxy = false;
					return;
				}
			}
			let proxyUsed = !destination.isCoolifyProxyUsed;
			try {
				await post(`/destinations/${id}/settings`, {
					isCoolifyProxyUsed: proxyUsed,
					engine: destination.engine
				});
				if (isProxyActivated) {
					await stopProxy();
				} else {
					await startProxy();
				}
				destination.isCoolifyProxyUsed = proxyUsed;
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.proxy = false;
			}
		}
	}
	async function stopProxy() {
		try {
			await post(`/destinations/${id}/stop`, { engine: destination.engine });
			return addToast({
				message: $t('destination.coolify_proxy_stopped'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function startProxy() {
		try {
			await post(`/destinations/${id}/start`, { engine: destination.engine });
			return addToast({
				message: $t('destination.coolify_proxy_started'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function forceRestartProxy() {
		const sure = confirm($t('destination.confirm_restart_proxy'));
		if (sure) {
			try {
				loading.restart = true;
				addToast({
					message: $t('destination.coolify_proxy_restarting'),
					type: 'success'
				});
				await post(`/destinations/${id}/restart`, {
					engine: destination.engine,
					fqdn: settings.fqdn
				});
			} catch (error) {
				setTimeout(() => {
					window.location.reload();
				}, 5000);
			} finally {
				loading.restart = false;
			}
		}
	}
	async function verifyRemoteDocker() {
		try {
			loading.verify = true;
			await post(`/destinations/${id}/verify`, {});
			destination.remoteVerified = true;
			return addToast({
				message: 'Remote Docker Engine verified!',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.verify = false;
		}
	}
</script>

<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
	<div class="flex space-x-1 pb-5">
		{#if $appSession.isAdmin}
			<button
				type="submit"
				class="btn btn-sm"
				class:loading={loading.save}
				class:bg-destinations={!loading.save}
				disabled={loading.save}
				>{$t('forms.save')}
			</button>
			<button
				disabled={loading.verify}
				class="btn btn-sm"
				class:loading={loading.verify}
				on:click|preventDefault|stopPropagation={verifyRemoteDocker}
				>{!destination.remoteVerified
					? 'Verify Remote Docker Engine'
					: 'Check Remote Docker Engine'}</button
			>
			{#if destination.remoteVerified}
				<button
					class="btn btn-sm"
					class:loading={loading.restart}
					class:bg-error={!loading.restart}
					disabled={loading.restart}
					on:click|preventDefault={forceRestartProxy}
					>{$t('destination.force_restart_proxy')}</button
				>
			{/if}
		{/if}
	</div>
	<div class="grid grid-cols-2 items-center px-10 ">
		<label for="name">{$t('forms.name')}</label>
		<input
			name="name"
			class="w-full"
			placeholder={$t('forms.name')}
			disabled={!$appSession.isAdmin}
			readonly={!$appSession.isAdmin}
			bind:value={destination.name}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="network">{$t('forms.network')}</label>
		<CopyPasswordField
			id="network"
			readonly
			disabled
			name="network"
			placeholder="{$t('forms.default')}: coolify"
			value={destination.network}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="remoteIpAddress">IP Address</label>
		<CopyPasswordField
			id="remoteIpAddress"
			readonly
			disabled
			name="remoteIpAddress"
			value={destination.remoteIpAddress}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="remoteUser">User</label>
		<CopyPasswordField
			id="remoteUser"
			readonly
			disabled
			name="remoteUser"
			value={destination.remoteUser}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="remotePort">Port</label>
		<CopyPasswordField
			id="remotePort"
			readonly
			disabled
			name="remotePort"
			value={destination.remotePort}
		/>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<label for="sshKey">SSH Key</label>
		<a
			href={!isDisabled ? `/destinations/${id}/configuration/sshkey?from=/destinations/${id}` : ''}
			class="no-underline"
			><input
				value={destination.sshKey.name}
				readonly
				id="sshKey"
				class="cursor-pointer w-full"
			/></a
		>
	</div>
	<div class="grid grid-cols-2 items-center px-10">
		<Setting
			id="changeProxySetting"
			disabled={cannotDisable || !destination.remoteVerified}
			loading={loading.proxy}
			bind:setting={destination.isCoolifyProxyUsed}
			on:click={changeProxySetting}
			title={$t('destination.use_coolify_proxy')}
			description={`Install & configure a proxy (based on Traefik) on the destination to allow you to access your applications and services without any manual configuration.${
				cannotDisable
					? '<span class="font-bold text-white">You cannot disable this proxy as FQDN is configured for Coolify.</span>'
					: ''
			}`}
		/>
	</div>
</form>
