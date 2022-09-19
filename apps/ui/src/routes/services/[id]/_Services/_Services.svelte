<script lang="ts">
	export let service: any;
	export let readOnly: any;
	export let settings: any;

	import cuid from 'cuid';
	import { onMount } from 'svelte';

	import { browser } from '$app/env';
	import { page } from '$app/stores';

	import { get, post } from '$lib/api';
	import { errorNotification, getDomain } from '$lib/common';
	import { t } from '$lib/translations';
	import {
		appSession,
		status,
		setLocation,
		addToast,
		checkIfDeploymentEnabledServices,
		isDeploymentEnabled
	} from '$lib/store';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Setting from '$lib/components/Setting.svelte';

	import Fider from './_Fider.svelte';
	import Ghost from './_Ghost.svelte';
	import GlitchTip from './_GlitchTip.svelte';
	import Hasura from './_Hasura.svelte';
	import MeiliSearch from './_MeiliSearch.svelte';
	import MinIo from './_MinIO.svelte';
	import PlausibleAnalytics from './_PlausibleAnalytics.svelte';
	import Umami from './_Umami.svelte';
	import VsCodeServer from './_VSCodeServer.svelte';
	import Wordpress from './_Wordpress.svelte';
	import Appwrite from './_Appwrite.svelte';
	import Moodle from './_Moodle.svelte';
	import Searxng from './_Searxng.svelte';
	import Weblate from './_Weblate.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import Taiga from './_Taiga.svelte';

	const { id } = $page.params;
	$: isDisabled =
		!$appSession.isAdmin || $status.service.isRunning || $status.service.initialLoading;

	let forceSave = false;
	let loading = {
		save: false,
		verification: false,
		cleanup: false
	};
	let dualCerts = service.dualCerts;

	let nonWWWDomain = service.fqdn && getDomain(service.fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;

	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/services/${id}/check?domain=${domain}`);
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

	async function handleSubmit() {
		if (loading.save) return;
		loading.save = true;
		try {
			await post(`/services/${id}/check`, {
				fqdn: service.fqdn,
				forceSave,
				dualCerts,
				otherFqdns: service.minio?.apiFqdn ? [service.minio?.apiFqdn] : [],
				exposePort: service.exposePort
			});
			await post(`/services/${id}`, { ...service });
			setLocation(service);
			forceSave = false;
			$isDeploymentEnabled = checkIfDeploymentEnabledServices($appSession.isAdmin, service);
			return addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			//@ts-ignore
			if (error?.message.startsWith($t('application.dns_not_set_partial_error'))) {
				forceSave = true;
				if (dualCerts) {
					isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
				} else {
					const isWWW = getDomain(service.fqdn).includes('www.');
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
	async function setEmailsToVerified() {
		loading.verification = true;
		try {
			await post(`/services/${id}/${service.type}/activate`, { id: service.id });
			return addToast({
				message: t.get('services.all_email_verified'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.verification = false;
		}
	}
	async function changeSettings(name: any) {
		try {
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			await post(`/services/${id}/settings`, { dualCerts });
			return addToast({
				message: t.get('application.settings_saved'),
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function cleanupLogs() {
		loading.cleanup = true;
		try {
			await post(`/services/${id}/${service.type}/cleanup`, { id: service.id });
			return addToast({
				message: 'Cleared DB Logs',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.cleanup = false;
		}
	}
	onMount(async () => {
		if (browser && window.location.hostname === 'demo.coolify.io' && !service.fqdn) {
			service.fqdn = `http://${cuid()}.demo.coolify.io`;
			if (service.type === 'wordpress') {
				service.wordpress.mysqlDatabase = 'db';
			}
			if (service.type === 'plausibleanalytics') {
				service.plausibleAnalytics.email = 'noreply@demo.com';
				service.plausibleAnalytics.username = 'admin';
			}
			if (service.type === 'minio') {
				service.minio.apiFqdn = `http://${cuid()}.demo.coolify.io`;
			}
			if (service.type === 'ghost') {
				service.ghost.mariadbDatabase = 'db';
			}
			if (service.type === 'fider') {
				service.fider.emailNoreply = 'noreply@demo.com';
			}
			await handleSubmit();
		}
	});
</script>

<div class="mx-auto max-w-6xl px-6 pb-12">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 items-center">
			<h1 class="title">{$t('general')}</h1>
			<div class="flex flex-row space-y-3 items-center">
				{#if $appSession.isAdmin}
					<button
						type="submit"
						class="btn btn-sm"
						class:bg-orange-600={forceSave}
						class:hover:bg-orange-400={forceSave}
						class:loading={loading.save}
						class:bg-services={!loading.save}
						disabled={loading.save}
						>{loading.save
							? $t('forms.save')
							: forceSave
							? $t('forms.confirm_continue')
							: $t('forms.save')}</button
					>
				{/if}
				{#if service.type === 'plausibleanalytics' && $status.service.isRunning}
					<button
						class="btn btn-sm"
						on:click|preventDefault={setEmailsToVerified}
						disabled={loading.verification}
						class:loading={loading.verification}
						>{loading.verification
							? $t('forms.verifying')
							: $t('forms.verify_emails_without_smtp')}</button
					>
					<button
						class="btn btn-sm"
						on:click|preventDefault={cleanupLogs}
						disabled={loading.cleanup}
						class:loading={loading.cleanup}>Cleanup Unnecessary Database Logs</button
					>
				{/if}
			</div>
		</div>

		{#if service.type === 'minio' && !service.minio.apiFqdn && $status.service.isRunning}
			<div class="py-5">
				<span class="font-bold text-red-500">IMPORTANT!</span> There was a small modification with Minio
				in the latest version of Coolify. Now you can separate the Console URL from the API URL, so you
				could use both through SSL. But this proccess cannot be done automatically, so you have to stop
				your Minio instance, configure the new domain and start it back. Sorry for any inconvenience.
			</div>
		{/if}

		<div class="grid gap-4 grid-cols-1 grid-rows-1 lg:px-10 px-2">
			<div class="mt-2 grid grid-cols-2 items-center">
				<label for="name">{$t('forms.name')}</label>
				<input name="name" id="name" class="w-full" bind:value={service.name} required />
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="version">Version / Tag</label>
				<a
					href={$appSession.isAdmin && !$status.service.isRunning && !$status.service.initialLoading
						? `/services/${id}/configuration/version?from=/services/${id}`
						: ''}
					class="no-underline"
				>
					<input
						class="w-full"
						value={service.version}
						id="service"
						readonly
						disabled={$status.service.isRunning || $status.service.initialLoading}
						class:cursor-pointer={!$status.service.isRunning}
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="destination">{$t('application.destination')}</label>
				<div>
					{#if service.destinationDockerId}
						<div class="no-underline">
							<input
								value={service.destinationDocker.name}
								id="destination"
								disabled
								class="bg-transparent w-full"
							/>
						</div>
					{/if}
				</div>
			</div>

			{#if service.type === 'minio'}
				<div class="grid grid-cols-2 items-center">
					<label for="fqdn">Console URL</label>

					<CopyPasswordField
						placeholder="eg: https://console.min.io"
						readonly={isDisabled}
						disabled={isDisabled}
						name="fqdn"
						id="fqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.fqdn}
						required
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="apiFqdn"
						>API URL <Explainer explanation={$t('application.https_explainer')} /></label
					>
					<CopyPasswordField
						placeholder="eg: https://min.io"
						readonly={!$appSession.isAdmin && !$status.service.isRunning}
						disabled={isDisabled}
						name="apiFqdn"
						id="apiFqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.minio.apiFqdn}
						required
					/>
				</div>
			{:else}
				<div class="grid grid-cols-2 items-center">
					<label for="fqdn"
						>{$t('application.url_fqdn')}
						<Explainer explanation={$t('application.https_explainer')} />
					</label>
					<CopyPasswordField
						placeholder="eg: https://analytics.coollabs.io"
						readonly={!$appSession.isAdmin && !$status.service.isRunning}
						disabled={!$appSession.isAdmin ||
							$status.service.isRunning ||
							$status.service.initialLoading}
						name="fqdn"
						id="fqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.fqdn}
						required
					/>
				</div>
			{/if}
		</div>
		{#if forceSave}
			<div class="flex-col space-y-2 pt-4 text-center">
				{#if isNonWWWDomainOK}
					<button
						class="btn btn-sm bg-green-600 hover:bg-green-500"
						on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
						>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
					>
				{:else}
					<button
						class="btn btn-sm bg-red-600 hover:bg-red-500"
						on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
						>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
					>
				{/if}
				{#if dualCerts}
					{#if isWWWDomainOK}
						<button
							class="btn btn-sm bg-green-600 hover:bg-green-500"
							on:click|preventDefault={() => isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
							>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
						>
					{:else}
						<button
							class="btn btn-sm bg-red-600 hover:bg-red-500"
							on:click|preventDefault={() => isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
							>DNS settings for www.{nonWWWDomain} is invalid, click to recheck.</button
						>
					{/if}
				{/if}
			</div>
		{/if}

		<div class="grid grid-flow-row gap-2 lg:px-10 px-2 pt-4">
			<div class="grid grid-cols-2 items-center">
				<label for="exposePort"
					>Exposed Port <Explainer
						explanation={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
					/></label
				>
				<input
					class="w-full"
					readonly={!$appSession.isAdmin && !$status.service.isRunning}
					disabled={!$appSession.isAdmin ||
						$status.service.isRunning ||
						$status.service.initialLoading}
					name="exposePort"
					id="exposePort"
					bind:value={service.exposePort}
					placeholder="12345"
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="dualCerts"
					disabled={$status.service.isRunning}
					dataTooltip={$t('forms.must_be_stopped_to_modify')}
					bind:setting={dualCerts}
					title={$t('application.ssl_www_and_non_www')}
					description={$t('services.generate_www_non_www_ssl')}
					on:click={() => !$status.service.isRunning && changeSettings('dualCerts')}
				/>
			</div>
		</div>
		{#if service.type === 'plausibleanalytics'}
			<PlausibleAnalytics bind:service {readOnly} />
		{:else if service.type === 'minio'}
			<MinIo {service} />
		{:else if service.type === 'vscodeserver'}
			<VsCodeServer {service} />
		{:else if service.type === 'wordpress'}
			<Wordpress bind:service {readOnly} {settings} />
		{:else if service.type === 'ghost'}
			<Ghost bind:service {readOnly} />
		{:else if service.type === 'meilisearch'}
			<MeiliSearch bind:service />
		{:else if service.type === 'umami'}
			<Umami bind:service />
		{:else if service.type === 'hasura'}
			<Hasura bind:service />
		{:else if service.type === 'fider'}
			<Fider bind:service {readOnly} />
		{:else if service.type === 'appwrite'}
			<Appwrite bind:service {readOnly} />
		{:else if service.type === 'moodle'}
			<Moodle bind:service {readOnly} />
		{:else if service.type === 'glitchTip'}
			<GlitchTip bind:service />
		{:else if service.type === 'searxng'}
			<Searxng bind:service />
		{:else if service.type === 'weblate'}
			<Weblate bind:service />
		{:else if service.type === 'taiga'}
			<Taiga bind:service />
		{/if}
	</form>
</div>
