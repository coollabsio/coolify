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
	import { browser } from '$app/env';
	import { t } from '$lib/translations';
	import { addToast, appSession, features } from '$lib/store';
	import { errorNotification, getDomain } from '$lib/common';
	import Menu from './_Menu.svelte';
	import Explainer from '$lib/components/Explainer.svelte';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;
	let isAutoUpdateEnabled = settings.isAutoUpdateEnabled;
	let isDNSCheckEnabled = settings.isDNSCheckEnabled;
	let DNSServers = settings.DNSServers;
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
			console.log(error);
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
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.settings')}</div>
</div>
<div class="mx-auto w-full">
	<div class="flex flex-row">
		<Menu />
		<div>
			<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
				<div class="flex space-x-1 pb-6">
					<div class="title font-bold">{$t('index.global_settings')}</div>
					<button
						class="btn btn-sm bg-settings text-black"
						type="submit"
						class:bg-orange-600={forceSave}
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
							class="btn btn-sm"
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
								<Explainer explanation={$t('setting.ssl_explainer')} />
							</div>
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
											class="btn btn-sm bg-success"
											on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
											>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
										>
									{:else}
										<button
											class="btn btn-sm bg-error"
											on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
											>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
										>
									{/if}
									{#if dualCerts}
										{#if isWWWDomainOK}
											<button
												class="btn btn-sm bg-success"
												on:click|preventDefault={() =>
													isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
												>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
											>
										{:else}
											<button
												class="btn btn-sm bg-error"
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
								<Explainer explanation={$t('forms.public_port_range_explainer')} />
							</div>
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
							id="isDNSCheckEnabled"
							bind:setting={isDNSCheckEnabled}
							title={$t('setting.is_dns_check_enabled')}
							description={$t('setting.is_dns_check_enabled_explainer')}
							on:click={() => changeSettings('isDNSCheckEnabled')}
						/>
					</div>
					<div class="grid grid-cols-2 items-center">
						<div class="text-base font-bold text-stone-100">
							Custom DNS servers <Explainer
								explanation="You can specify a custom DNS server to verify your domains all over Coolify.<br><br>By default, the OS defined DNS servers are used."
							/>
						</div>

						<div class="flex-row items-center justify-center">
							<input placeholder="1.1.1.1,8.8.8.8" bind:value={DNSServers} />
						</div>
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
						<Setting
							id="isRegistrationEnabled"
							bind:setting={isRegistrationEnabled}
							title={$t('setting.registration_allowed')}
							description={$t('setting.registration_allowed_explainer')}
							on:click={() => changeSettings('isRegistrationEnabled')}
						/>
					</div>
					{#if browser && $features.beta}
						<div class="grid grid-cols-2 items-center">
							<Setting
								id="isAutoUpdateEnabled"
								bind:setting={isAutoUpdateEnabled}
								title={$t('setting.auto_update_enabled')}
								description={$t('setting.auto_update_enabled_explainer')}
								on:click={() => changeSettings('isAutoUpdateEnabled')}
							/>
						</div>
					{/if}
				</div>
			</form>
		</div>
	</div>
</div>
