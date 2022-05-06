<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch }) => {
		const url = `/settings.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}
		if (res.status === 401) {
			return {
				status: 302,
				redirect: '/databases'
			};
		}
		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	import { session } from '$app/stores';

	export let settings;
	import Setting from '$lib/components/Setting.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { errorNotification } from '$lib/form';
	import { del, get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { browser } from '$app/env';
	import { getDomain } from '$lib/components/common';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';
	import { features } from '$lib/store';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;
	let isAutoUpdateEnabled = settings.isAutoUpdateEnabled;
	let isDNSCheckEnabled = settings.isDNSCheckEnabled;

	let minPort = settings.minPort;
	let maxPort = settings.maxPort;

	let forceSave = false;
	let fqdn = settings.fqdn;
	let nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');
	let isFqdnSet = !!settings.fqdn;
	let loading = {
		save: false,
		remove: false
	};

	async function removeFqdn() {
		if (fqdn) {
			loading.remove = true;
			try {
				const { redirect } = await del(`/settings.json`, { fqdn });
				return redirect ? window.location.replace(redirect) : window.location.reload();
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				loading.remove = false;
			}
		}
	}
	async function changeSettings(name) {
		try {
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
			await post(`/settings.json`, {
				isRegistrationEnabled,
				dualCerts,
				isAutoUpdateEnabled,
				isDNSCheckEnabled
			});
			return toast.push(t.get('application.settings_saved'));
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			loading.save = true;
			nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');
			if (fqdn !== settings.fqdn) {
				await post(`/settings/check.json`, { fqdn, forceSave, dualCerts, isDNSCheckEnabled });
				await post(`/settings.json`, { fqdn });
				return window.location.reload();
			}
			if (minPort !== settings.minPort || maxPort !== settings.maxPort) {
				await post(`/settings.json`, { minPort, maxPort });
				settings.minPort = minPort;
				settings.maxPort = maxPort;
			}
			forceSave = false;
		} catch ({ error }) {
			if (error?.startsWith($t('application.dns_not_set_partial_error'))) {
				forceSave = true;
			}
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
	async function renewCerts() {
		try {
			toast.push('Renewing certificates...');
			return await post(`/settings/renew.json`, {});
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function isDNSValid(domain) {
		try {
			await get(`/settings/check.json?domain=${domain}`);
			toast.push('Domain is valid in DNS.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.settings')}</div>
</div>
{#if $session.teamId === '0'}
	<div class="mx-auto max-w-4xl px-6">
		<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
			<div class="flex space-x-1 pb-6">
				<div class="title font-bold">{$t('index.global_settings')}</div>
				<button
					type="submit"
					class:bg-green-600={!loading.save}
					class:bg-orange-600={forceSave}
					class:hover:bg-green-500={!loading.save}
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
							readonly={!$session.isAdmin || isFqdnSet}
							disabled={!$session.isAdmin || isFqdnSet}
							name="fqdn"
							id="fqdn"
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="{$t('forms.eg')}: https://coolify.io"
						/>

						{#if forceSave}
							<div class="pt-4">
								<button
									class="bg-coollabs hover:bg-coollabs-100"
									on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain))}
									>Check {nonWWWDomain} DNS Record</button
								>
								{#if dualCerts}
									<button
										class="bg-coollabs hover:bg-coollabs-100"
										on:click|preventDefault={() => isDNSValid(getDomain(`www.${nonWWWDomain}`))}
										>Check www.{nonWWWDomain} DNS Record</button
									>
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
					<div class="grid grid-cols-2 items-center">
						<div class="flex flex-col">
							<div class="pt-2 text-base font-bold text-stone-100">
								Renew SSL Certificates manually
							</div>
							<Explainer text="It will check and renew certificates manually" />
						</div>
						<div class="mx-auto ">
							<button class="w-32 bg-coollabs hover:bg-coollabs-100" on:click={renewCerts}
								>SSL renew manually</button
							>
						</div>
					</div>
				{/if}
			</div>
		</form>
		<div class="flex space-x-1 pt-6 font-bold">
			<div class="title">{$t('setting.coolify_proxy_settings')}</div>
		</div>
		<Explainer
			text={$t('setting.credential_stat_explainer', {
				link: fqdn
					? `http://${settings.proxyUser}:${settings.proxyPassword}@` + getDomain(fqdn) + ':8404'
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
	</div>
{:else}
	<div class="mx-auto max-w-4xl px-6">
		<!-- <Language /> -->
	</div>
{/if}
