<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/settings`);
			return {
				props: {
					...response
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
	export let sshKeys: any;
	import Setting from '$lib/components/Setting.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { del, get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { browser } from '$app/env';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';
	import { appSession, features, isTraefikUsed } from '$lib/store';
	import { errorNotification, getDomain } from '$lib/common';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;
	let isAutoUpdateEnabled = settings.isAutoUpdateEnabled;
	let isDNSCheckEnabled = settings.isDNSCheckEnabled;
	$isTraefikUsed = settings.isTraefikUsed;

	let minPort = settings.minPort;
	let maxPort = settings.maxPort;

	let forceSave = false;
	let fqdn = settings.fqdn;
	let nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;
	let isFqdnSet = !!settings.fqdn;
	let loading = {
		save: false,
		remove: false,
		proxyMigration: false
	};

	let subMenuActive: any = 'globalsettings';
	let isModalActive = false;

	let newSSHKey = {
		name: null,
		privateKey: null
	};
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

			await post(`/settings`, {
				isRegistrationEnabled,
				dualCerts,
				isAutoUpdateEnabled,
				isDNSCheckEnabled
			});
			return toast.push(t.get('application.settings_saved'));
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
			if (minPort !== settings.minPort || maxPort !== settings.maxPort) {
				await post(`/settings`, { minPort, maxPort });
				settings.minPort = minPort;
				settings.maxPort = maxPort;
			}
			forceSave = false;
		} catch (error) {
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
			console.log(error);
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/settings/check?domain=${domain}`);
			toast.push('DNS configuration is valid.');
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
	async function saveSSHKey() {
		try {
			await post(`/settings/sshKey`, { ...newSSHKey });
			return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function deleteSSHKey(id: string) {
		try {
			if (!id) return
			await del(`/settings/sshKey`, { id });
			return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.settings')}</div>
</div>
<div class="mx-auto w-full">
	<div class="flex flex-row">
		<div class="flex flex-col pt-4 space-y-6 w-96 px-20">
			<div
				class="sub-menu"
				class:sub-menu-active={subMenuActive === 'globalsettings'}
				on:click={() => (subMenuActive = 'globalsettings')}
			>
				Global Settings
			</div>
			<div
				class="sub-menu"
				class:sub-menu-active={subMenuActive === 'sshkey'}
				on:click={() => (subMenuActive = 'sshkey')}
			>
				SSH Keys
			</div>
		</div>
		<div class="pl-40">
			{#if $appSession.teamId === '0'}
				{#if subMenuActive === 'globalsettings'}
					<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
						<div class="flex space-x-1 pb-6">
							<div class="title font-bold">{$t('index.global_settings')}</div>
							<button
								type="submit"
								class:bg-yellow-500={!loading.save}
								class:bg-orange-600={forceSave}
								class:hover:bg-yellow-500={!loading.save}
								class:hover:bg-orange-400={forceSave}
								disabled={loading.save}
								>{loading.save
									? $t('forms.saving')
									: forceSave
									? $t('forms.confirm_continue')
									: $t('forms.save')}</button
							>

							{#if isFqdnSet}
								<button
									on:click|preventDefault={removeFqdn}
									disabled={loading.remove}
									class:bg-red-600={!loading.remove}
									class:hover:bg-red-500={!loading.remove}
									>{loading.remove ? $t('forms.removing') : $t('forms.remove_domain')}</button
								>
							{/if}
						</div>
						<div class="grid grid-flow-row gap-2 px-10">
							<!-- <Language /> -->
							<div class="grid grid-cols-2 items-start">
								<div class="flex-col">
									<div class="pt-2 text-base font-bold text-stone-100">
										{$t('application.url_fqdn')}
									</div>
									<Explainer text={$t('setting.ssl_explainer')} />
								</div>
								<div class="justify-start text-left">
									<input
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
													class="bg-green-600 hover:bg-green-500"
													on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
													>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
												>
											{:else}
												<button
													class="bg-red-600 hover:bg-red-500"
													on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
													>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
												>
											{/if}
											{#if dualCerts}
												{#if isWWWDomainOK}
													<button
														class="bg-green-600 hover:bg-green-500"
														on:click|preventDefault={() =>
															isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
														>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
													>
												{:else}
													<button
														class="bg-red-600 hover:bg-red-500"
														on:click|preventDefault={() =>
															isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
														>DNS settings for www.{nonWWWDomain} is invalid, click to recheck.</button
													>
												{/if}
											{/if}
										</div>
									{/if}
								</div>
							</div>
							<div class="grid grid-cols-2 items-start py-6">
								<div class="flex-col">
									<div class="pt-2 text-base font-bold text-stone-100">
										{$t('forms.public_port_range')}
									</div>
									<Explainer text={$t('forms.public_port_range_explainer')} />
								</div>
								<div class="mx-auto flex-row items-center justify-center space-y-2">
									<input
										class="h-8 w-20 px-2"
										type="number"
										bind:value={minPort}
										min="1024"
										max={maxPort}
									/>
									-
									<input
										class="h-8 w-20 px-2"
										type="number"
										bind:value={maxPort}
										min={minPort}
										max="65543"
									/>
								</div>
							</div>
							<div class="grid grid-cols-2 items-center">
								<Setting
									bind:setting={isDNSCheckEnabled}
									title={$t('setting.is_dns_check_enabled')}
									description={$t('setting.is_dns_check_enabled_explainer')}
									on:click={() => changeSettings('isDNSCheckEnabled')}
								/>
							</div>
							<div class="grid grid-cols-2 items-center">
								<Setting
									dataTooltip={$t('setting.must_remove_domain_before_changing')}
									disabled={isFqdnSet}
									bind:setting={dualCerts}
									title={$t('application.ssl_www_and_non_www')}
									description={$t('setting.generate_www_non_www_ssl')}
									on:click={() => !isFqdnSet && changeSettings('dualCerts')}
								/>
							</div>
							<div class="grid grid-cols-2 items-center">
								<Setting
									bind:setting={isRegistrationEnabled}
									title={$t('setting.registration_allowed')}
									description={$t('setting.registration_allowed_explainer')}
									on:click={() => changeSettings('isRegistrationEnabled')}
								/>
							</div>
							{#if browser && $features.beta}
								<div class="grid grid-cols-2 items-center">
									<Setting
										bind:setting={isAutoUpdateEnabled}
										title={$t('setting.auto_update_enabled')}
										description={$t('setting.auto_update_enabled_explainer')}
										on:click={() => changeSettings('isAutoUpdateEnabled')}
									/>
								</div>
							{/if}
						</div>
					</form>
					{#if !settings.isTraefikUsed}
						<div class="flex space-x-1 pt-6 font-bold">
							<div class="title">{$t('setting.coolify_proxy_settings')}</div>
						</div>
						<Explainer
							text={$t('setting.credential_stat_explainer', {
								link: fqdn
									? `http://${settings.proxyUser}:${settings.proxyPassword}@` +
									  getDomain(fqdn) +
									  ':8404'
									: browser &&
									  `http://${settings.proxyUser}:${settings.proxyPassword}@` +
											window.location.hostname +
											':8404'
							})}
						/>
						<div class="space-y-2 px-10 py-5">
							<div class="grid grid-cols-2 items-center">
								<label for="proxyUser">{$t('forms.user')}</label>
								<CopyPasswordField
									readonly
									disabled
									id="proxyUser"
									name="proxyUser"
									value={settings.proxyUser}
								/>
							</div>
							<div class="grid grid-cols-2 items-center">
								<label for="proxyPassword">{$t('forms.password')}</label>
								<CopyPasswordField
									readonly
									disabled
									id="proxyPassword"
									name="proxyPassword"
									isPasswordField
									value={settings.proxyPassword}
								/>
							</div>
						</div>
					{/if}
				{/if}
				{#if subMenuActive === 'sshkey'}
					<div class="grid grid-flow-row gap-2 py-4">
						<div class="flex space-x-1 pb-6">
							<div class="title font-bold">SSH Keys</div>
							<button
								on:click={() => (isModalActive = true)}
								class:bg-yellow-500={!loading.save}
								class:hover:bg-yellow-400={!loading.save}
								disabled={loading.save}>New SSH Key</button
							>
						</div>
						<div class="grid grid-flow-col gap-2 px-10">
							{#if sshKeys.length === 0}
								<div class="text-sm ">No SSH keys found</div>
							{:else}
								{#each sshKeys as key}
									<div class="box-selection group relative">
										<div class="text-xl font-bold">{key.name}</div>
										<div class="py-3 text-stone-600">Added on {key.createdAt}</div>
										<button on:click={() => deleteSSHKey(key.id)} class="bg-red-500">Delete</button>
									</div>
								{/each}
							{/if}
						</div>
					</div>
				{/if}
			{/if}
		</div>
	</div>
</div>

{#if isModalActive}
	<div class="relative z-10" aria-labelledby="modal-title" role="dialog" aria-modal="true">
		<div class="fixed inset-0 bg-coolgray-500 bg-opacity-75 transition-opacity" />

		<div class="fixed z-10 inset-0 overflow-y-auto text-white">
			<div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">
				<div
					class="relative bg-coolblack rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg sm:w-full sm:p-6  border border-coolgray-500"
				>
					<div class="hidden sm:block absolute top-0 right-0 pt-4 pr-4">
						<button
							on:click={() => (isModalActive = false)}
							type="button"
							class=" rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
						>
							<span class="sr-only">Close</span>
							<svg
								class="h-6 w-6"
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke-width="2"
								stroke="currentColor"
								aria-hidden="true"
							>
								<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
					</div>
					<div class="sm:flex sm:items-start">
						<div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
							<h3 class="text-lg leading-6 font-medium pb-4" id="modal-title">New SSH Key</h3>
							<div class="text-xs text-stone-400">Add an SSH key to your Coolify instance.</div>
							<div class="mt-2">
								<label for="privateKey" class="pb-2">Key</label>
								<textarea
									id="privateKey"
									required
									bind:value={newSSHKey.privateKey}
									class="w-full"
									rows={15}
								/>
							</div>
							<div class="mt-2">
								<label for="name" class="pb-2">Name</label>
								<input id="name" required bind:value={newSSHKey.name} class="w-full" />
							</div>
						</div>
					</div>
					<div class="mt-5 flex space-x-4 justify-end">
						<button on:click={saveSSHKey} type="button" class="bg-green-600 hover:bg-green-500"
							>Save</button
						>
						<button on:click={() => (isModalActive = false)} type="button" class="">Cancel</button>
					</div>
				</div>
			</div>
		</div>
	</div>
{/if}
