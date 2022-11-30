<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		try {
			return {
				props: {
					...stuff
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let settings: any;
	import Setting from '$lib/components/Setting.svelte';
	import { del, get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { addToast, appSession, features } from '$lib/store';
	import { asyncSleep, errorNotification, getDomain } from '$lib/common';
	import Explainer from '$lib/components/Explainer.svelte';
	import { dev } from '$app/env';

	let isAPIDebuggingEnabled = settings.isAPIDebuggingEnabled;
	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;
	let isAutoUpdateEnabled = settings.isAutoUpdateEnabled;
	let isDNSCheckEnabled = settings.isDNSCheckEnabled;
	let DNSServers = settings.DNSServers;
	let minPort = settings.minPort;
	let maxPort = settings.maxPort;
	let proxyDefaultRedirect = settings.proxyDefaultRedirect;
	let doNotTrack = settings.doNotTrack;
	let numberOfDockerImagesKeptLocally = settings.numberOfDockerImagesKeptLocally;
	let previewSeparator = settings.previewSeparator;

	let forceSave = false;
	let fqdn = settings.fqdn;
	let nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;
	let isFqdnSet = !!settings.fqdn;
	let loading = {
		save: false,
		remove: false,
		proxyMigration: false,
		restart: false,
		rollback: false
	};
	let rollbackVersion = localStorage.getItem('lastVersion');

	async function rollback() {
		if (rollbackVersion) {
			const sure = confirm(`Are you sure you want rollback Coolify to ${rollbackVersion}?`);
			if (sure) {
				try {
					loading.rollback = true;
					console.log('loading.rollback', loading.rollback);
					if (dev) {
						console.log('rolling back to', rollbackVersion);
						await asyncSleep(4000);
						return window.location.reload();
					} else {
						addToast({
							message: 'Rollback started...',
							type: 'success'
						});
						await post(`/update`, { type: 'update', latestVersion: rollbackVersion });
						addToast({
							message: 'Rollback completed.<br><br>Waiting for the new version to start...',
							type: 'success'
						});

						let reachable = false;
						let tries = 0;
						do {
							await asyncSleep(4000);
							try {
								await get(`/undead`);
								reachable = true;
							} catch (error) {
								reachable = false;
							}
							if (reachable) break;
							tries++;
						} while (!reachable || tries < 120);
						addToast({
							message: 'New version reachable. Reloading...',
							type: 'success'
						});
						await asyncSleep(3000);
						return window.location.reload();
					}
				} catch (error) {
					return errorNotification(error);
				} finally {
					loading.rollback = false;
				}
			}
		}
	}
	async function removeFqdn() {
		if (fqdn) {
			loading.remove = true;
			try {
				const { redirect } = await del(`/settings`, { fqdn });
				return redirect ? window.location.replace(redirect) : window.location.reload();
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.remove = false;
			}
		}
	}
	async function changeSettings(name: any) {
		try {
			resetView();
			if (name === 'doNotTrack') {
				doNotTrack = !doNotTrack;
			}
			if (name === 'isRegistrationEnabled') {
				isRegistrationEnabled = !isRegistrationEnabled;
			}
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			if (name === 'isAutoUpdateEnabled') {
				isAutoUpdateEnabled = !isAutoUpdateEnabled;
			}
			if (name === 'isDNSCheckEnabled') {
				isDNSCheckEnabled = !isDNSCheckEnabled;
			}
			if (name === 'isAPIDebuggingEnabled') {
				isAPIDebuggingEnabled = !isAPIDebuggingEnabled;
			}
			await post(`/settings`, {
				doNotTrack,
				isAPIDebuggingEnabled,
				isRegistrationEnabled,
				dualCerts,
				isAutoUpdateEnabled,
				isDNSCheckEnabled
			});
			return addToast({
				message: t.get('application.settings_saved'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			loading.save = true;
			nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');

			if (fqdn !== settings.fqdn) {
				await post(`/settings/check`, { fqdn, forceSave, dualCerts, isDNSCheckEnabled });
				await post(`/settings`, { fqdn });
				return window.location.reload();
			}
			if (proxyDefaultRedirect !== settings.proxyDefaultRedirect) {
				await post(`/settings`, { proxyDefaultRedirect });
				settings.proxyDefaultRedirect = proxyDefaultRedirect;
			}
			if (numberOfDockerImagesKeptLocally !== settings.numberOfDockerImagesKeptLocally) {
				await post(`/settings`, { numberOfDockerImagesKeptLocally });
				settings.numberOfDockerImagesKeptLocally = numberOfDockerImagesKeptLocally;
			}
			if (previewSeparator !== settings.previewSeparator) {
				await post(`/settings`, { previewSeparator });
				settings.previewSeparator = previewSeparator;
			}
			if (minPort !== settings.minPort || maxPort !== settings.maxPort) {
				await post(`/settings`, { minPort, maxPort });
				settings.minPort = minPort;
				settings.maxPort = maxPort;
			}
			if (DNSServers !== settings.DNSServers) {
				await post(`/settings`, { DNSServers });
				settings.DNSServers = DNSServers;
			}
			forceSave = false;
			return addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error: any) {
			if (error.message?.startsWith($t('application.dns_not_set_partial_error'))) {
				forceSave = true;
				if (dualCerts) {
					isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
				} else {
					const isWWW = getDomain(settings.fqdn).includes('www.');
					if (isWWW) {
						isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
					} else {
						isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					}
				}
			}
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/settings/check?domain=${domain}`);
			addToast({
				message: 'DNS configuration is valid.',
				type: 'success'
			});
			isWWW ? (isWWWDomainOK = true) : (isNonWWWDomainOK = true);
			return true;
		} catch (error) {
			errorNotification(error);
			isWWW ? (isWWWDomainOK = false) : (isNonWWWDomainOK = false);
			return false;
		}
	}
	function resetView() {
		forceSave = false;
	}
	async function restartCoolify() {
		const sure = confirm(
			'Are you sure you would like to restart Coolify? Currently running deployments will be stopped and restarted.'
		);
		if (sure) {
			loading.restart = true;
			try {
				await post(`/internal/restart`, {});
				await asyncSleep(10000);
				let reachable = false;
				let tries = 0;
				do {
					await asyncSleep(4000);
					try {
						await get(`/undead`);
						reachable = true;
					} catch (error) {
						reachable = false;
					}
					if (reachable) break;
					tries++;
				} while (!reachable || tries < 120);
				addToast({
					message: 'New version reachable. Reloading...',
					type: 'success'
				});
				await asyncSleep(3000);
				return window.location.reload();
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.restart = false;
			}
		}
	}
</script>

<div class="mx-auto w-full">
	<form on:submit|preventDefault={handleSubmit}>
		<div class="flex flex-row border-b border-coolgray-500 mb-6">
			<div class="title font-bold pb-3 pr-4">Coolify Settings</div>
			<div class="flex flex-row space-x-2">
				<button
					class="btn btn-sm btn-primary"
					type="submit"
					class:bg-orange-600={forceSave}
					class:hover:bg-orange-400={forceSave}
					class:loading={loading.save}
					disabled={loading.save}
					>{loading.save
						? $t('forms.saving')
						: forceSave
						? $t('forms.confirm_continue')
						: $t('forms.save')}</button
				>

				{#if isFqdnSet}
					<button on:click|preventDefault={removeFqdn} disabled={loading.remove} class="btn btn-sm"
						>{loading.remove ? $t('forms.removing') : $t('forms.remove_domain')}</button
					>
				{/if}
				<button
					on:click={restartCoolify}
					class:loading={loading.restart}
					class="btn btn-sm btn-error">Restart Coolify</button
				>
			</div>
		</div>
		<div class="flex lg:flex-row flex-col">
			<div class="grid grid-flow-row gap-2 px-4 pr-5">
				<div class="grid grid-cols-2 items-center">
					<div>
						{$t('application.url_fqdn')}
						<Explainer position="dropdown-bottom" explanation={$t('setting.ssl_explainer')} />
					</div>
					<input
						class="w-full"
						bind:value={fqdn}
						readonly={!$appSession.isAdmin || isFqdnSet}
						disabled={!$appSession.isAdmin || isFqdnSet}
						on:input={resetView}
						name="fqdn"
						id="fqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						placeholder="{$t('forms.eg')}: https://coolify.io"
					/>

					{#if forceSave}
						<div class="flex-col space-y-2 pt-4 text-center">
							{#if isNonWWWDomainOK}
								<button
									class="btn bg-success"
									on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
									>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
								>
							{:else}
								<button
									class="btn btn-error"
									on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
									>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
								>
							{/if}
							{#if dualCerts}
								{#if isWWWDomainOK}
									<button
										class="btn bg-success"
										on:click|preventDefault={() =>
											isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
										>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
									>
								{:else}
									<button
										class="btn btn-error"
										on:click|preventDefault={() =>
											isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
										>DNS settings for www.{nonWWWDomain} is invalid, click to recheck.</button
									>
								{/if}
							{/if}
						</div>
					{/if}
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="dualCerts"
						dataTooltip={$t('setting.must_remove_domain_before_changing')}
						disabled={isFqdnSet}
						bind:setting={dualCerts}
						title={$t('application.ssl_www_and_non_www')}
						description={$t('setting.generate_www_non_www_ssl')}
						on:click={() => !isFqdnSet && changeSettings('dualCerts')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<div>
						Default Redirect URL
						<Explainer
							position="dropdown-bottom"
							explanation="You can specify where to redirect all requests that does not have a running resource."
						/>
					</div>
					<input
						class="w-full"
						bind:value={proxyDefaultRedirect}
						readonly={!$appSession.isAdmin}
						disabled={!$appSession.isAdmin}
						name="proxyDefaultRedirect"
						id="proxyDefaultRedirect"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						placeholder="{$t('forms.eg')}: https://coolify.io"
					/>
				</div>

				<div class="grid grid-cols-4 items-center">
					<div class="col-span-2">
						Rollback Coolify to a specific version
						<Explainer
							position="dropdown-bottom"
							explanation="You can rollback to a specific version of Coolify. This will not affect your current running resources.<br><br><a href='https://github.com/coollabsio/coolify/releases' target='_blank'>See available versions</a>"
						/>
					</div>
					<input
						class="w-full"
						bind:value={rollbackVersion}
						readonly={!$appSession.isAdmin}
						disabled={!$appSession.isAdmin}
						name="rollbackVersion"
						id="rollbackVersion"
					/>
					<button
						class:loading={loading.rollback}
						class="btn btn-primary ml-2"
						disabled={!rollbackVersion || loading.rollback}
						on:click|preventDefault|stopPropagation={rollback}>Rollback</button
					>
				</div>
				<div class="grid grid-cols-2 items-center">
					<div>
						Number of Docker Images kept locally
						<Explainer
							position="dropdown-bottom"
							explanation="The number of Docker images kept locally on the server for EACH application. The oldest images will be deleted when the limit is reached.<br><br>Useful to rollback to a specific version of your applications quickly, but it will use more storage locally."
						/>
					</div>
					<input
						type="number"
						class="w-full"
						bind:value={numberOfDockerImagesKeptLocally}
						readonly={!$appSession.isAdmin}
						disabled={!$appSession.isAdmin}
						name="numberOfDockerImagesKeptLocally"
						id="numberOfDockerImagesKeptLocally"
						placeholder="default: 3"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<div>
						Preview Domain Separator
						<Explainer
							position="dropdown-bottom"
							explanation="The separator used in the PR/MR previews.<br><br>For example if you set it to: <span class='text-yellow-400 font-bold'>-</span><br> the preview domain will be like this: <br><br><span class='text-yellow-400 font-bold'>PRMRNumber-yourdomain.com</span><br><br>The default is: <span class='text-yellow-400 font-bold'>.</span><br>so the preview domain will be like this: <br><br><span class='text-yellow-400 font-bold'>PRMRNumber.yourdomain.com</span>"
						/>
					</div>
					<input
						class="w-full"
						required
						bind:value={previewSeparator}
						readonly={!$appSession.isAdmin}
						disabled={!$appSession.isAdmin}
						name="previewSeparator"
						id="previewSeparator"
						placeholder="default: ."
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<div>
						{$t('forms.public_port_range')}
						<Explainer explanation={$t('forms.public_port_range_explainer')} />
					</div>

					<div class="flex flex-row items-center space-x-2">
						<input
							class="w-full px-2 "
							type="number"
							bind:value={minPort}
							min="1024"
							max={maxPort}
						/>
						<p>-</p>
						<input
							class="w-full px-2 "
							type="number"
							bind:value={maxPort}
							min={minPort}
							max="65543"
						/>
					</div>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="isDNSCheckEnabled"
						bind:setting={isDNSCheckEnabled}
						title={$t('setting.is_dns_check_enabled')}
						description={$t('setting.is_dns_check_enabled_explainer')}
						on:click={() => changeSettings('isDNSCheckEnabled')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<div>
						Custom DNS servers <Explainer
							explanation="You can specify a custom DNS server to verify your domains all over Coolify.<br><br>By default, the OS defined DNS servers are used."
						/>
					</div>

					<input class="w-full " placeholder="1.1.1.1,8.8.8.8" bind:value={DNSServers} />
				</div>

				<div class="grid grid-cols-2 items-center">
					<Setting
						id="isRegistrationEnabled"
						bind:setting={isRegistrationEnabled}
						title={$t('setting.registration_allowed')}
						description={$t('setting.registration_allowed_explainer')}
						on:click={() => changeSettings('isRegistrationEnabled')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="isAPIDebuggingEnabled"
						bind:setting={isAPIDebuggingEnabled}
						title="API Debugging"
						description="Enable API debugging. This will log all API requests and responses.<br><br>You need to restart the Coolify for this to take effect."
						on:click={() => changeSettings('isAPIDebuggingEnabled')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="isAutoUpdateEnabled"
						bind:setting={isAutoUpdateEnabled}
						title={$t('setting.auto_update_enabled')}
						description={$t('setting.auto_update_enabled_explainer')}
						on:click={() => changeSettings('isAutoUpdateEnabled')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						id="doNotTrack"
						bind:setting={doNotTrack}
						title="Do Not Track"
						description="Do not send error reports to Coolify developers or any telemetry."
						on:click={() => changeSettings('doNotTrack')}
					/>
				</div>
			</div>
		</div>
	</form>
</div>
