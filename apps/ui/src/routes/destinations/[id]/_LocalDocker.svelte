<script lang="ts">
	export let destination: any;
	export let settings: any;

	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { onMount } from 'svelte';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession } from '$lib/store';
	import Setting from '$lib/components/Setting.svelte';

	const { id } = $page.params;
	let cannotDisable = settings.fqdn && destination.engine === '/var/run/docker.sock';
	let loading = {
		restart: false,
		proxy: false,
		save: false
	};

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
		loading.proxy = true;
		const { isRunning } = await get(`/destinations/${id}/status`);
		let proxyUsed = !destination.isCoolifyProxyUsed;
		if (isRunning === false && destination.isCoolifyProxyUsed === true) {
			try {
				await post(`/destinations/${id}/settings`, {
					isCoolifyProxyUsed: proxyUsed,
					engine: destination.engine
				});
				await stopProxy();
			} catch (error) {
				return errorNotification(error);
			}
		} else if (isRunning === true && destination.isCoolifyProxyUsed === false) {
			try {
				await post(`/destinations/${id}/settings`, {
					isCoolifyProxyUsed: proxyUsed,
					engine: destination.engine
				});
				await startProxy();
				destination.isCoolifyProxyUsed = proxyUsed;
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.proxy = false;
			}
		}
		loading.proxy = false;
	});
	async function changeProxySetting() {
		if (!cannotDisable) {
			const isProxyActivated = destination.isCoolifyProxyUsed;
			if (isProxyActivated) {
				const sure = confirm(
					`Are you sure you want to ${
						destination.isCoolifyProxyUsed ? 'disable' : 'enable'
					} Coolify proxy? It will remove the proxy for all configured networks and all deployments on '${
						destination.engine
					}'! Nothing will be reachable if you do it!`
				);
				if (!sure) return;
			}
			destination.isCoolifyProxyUsed = !destination.isCoolifyProxyUsed;
			try {
				loading.proxy = true;
				await post(`/destinations/${id}/settings`, {
					isCoolifyProxyUsed: destination.isCoolifyProxyUsed,
					engine: destination.engine
				});
				if (isProxyActivated) {
					await stopProxy();
				} else {
					await startProxy();
				}
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
</script>

<form on:submit|preventDefault={handleSubmit} class="py-4">
	<div class="flex space-x-2">
		<button
			type="submit"
			class="btn btn-sm"
			class:bg-destinations={!loading.save}
			class:loading={loading.save}
			disabled={loading.save}
			>{$t('forms.save')}
		</button>
		<button
			class="btn btn-sm"
			class:loading={loading.restart}
			class:bg-error={!loading.restart}
			disabled={loading.restart}
			on:click|preventDefault={forceRestartProxy}>{$t('destination.force_restart_proxy')}</button
		>
	</div>
	<div class="grid gap-2 grid-cols-2 auto-rows-max mt-10 items-center">
		<label for="name">{$t('forms.name')}</label>
		<input
			class="w-full"
			name="name"
			placeholder={$t('forms.name')}
			disabled={!$appSession.isAdmin}
			readonly={!$appSession.isAdmin}
			bind:value={destination.name}
		/>
		<label for="engine">{$t('forms.engine')}</label>
		<CopyPasswordField
			id="engine"
			readonly
			disabled
			name="engine"
			placeholder="{$t('forms.eg')}: /var/run/docker.sock"
			value={destination.engine}
		/>
		<label for="network">{$t('forms.network')}</label>
		<CopyPasswordField
			id="network"
			readonly
			disabled
			name="network"
			placeholder="{$t('forms.default')}: coolify"
			value={destination.network}
		/>
		{#if $appSession.teamId === '0'}
			<Setting
				id="changeProxySetting"
				loading={loading.proxy}
				disabled={cannotDisable}
				bind:setting={destination.isCoolifyProxyUsed}
				on:click={changeProxySetting}
				title={$t('destination.use_coolify_proxy')}
				description={`This will install a proxy on the destination to allow you to access your applications and services without any manual configuration.${
					cannotDisable
						? '<span class="font-bold text-white">You cannot disable this proxy as FQDN is configured for Coolify.</span>'
						: ''
				}`}
			/>
		{/if}
	</div>
</form>
