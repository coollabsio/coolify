<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let service: any;
	export let template: any;
	export let tags: any;
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

	import DocLink from '$lib/components/DocLink.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import ServiceStatus from '$lib/components/ServiceStatus.svelte';
	import { saveForm } from './utils';
	import Select from 'svelte-select';
	import Wordpress from './_Services/wordpress.svelte';

	const { id } = $page.params;
	let hostPorts = Object.keys(template).filter((key) => {
		if (template[key]?.hostPorts?.length > 0) {
			return true;
		}
	});
	$: isDisabled =
		!$appSession.isAdmin ||
		$status.service.overallStatus === 'degraded' ||
		$status.service.overallStatus === 'healthy' ||
		$status.service.initialLoading;

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

	function containerClass() {
		return 'text-white bg-transparent font-thin px-0 w-full border border-dashed border-coolgray-200';
	}
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

	async function handleSubmit(e: any) {
		if (loading.save) return;
		loading.save = true;
		try {
			const formData = new FormData(e.target);
			await post(`/services/${id}/check`, {
				fqdn: service.fqdn,
				forceSave,
				dualCerts,
				exposePort: service.exposePort
			});
			for (const setting of service.serviceSetting) {
				if (setting.variableName?.startsWith('$$config_coolify_fqdn') && setting.value) {
					for (let field of formData) {
						const [key, value] = field;
						if (setting.name === key) {
							if (setting.value !== value) {
								await post(`/services/${id}/check`, {
									fqdn: value,
									otherFqdn: true
								});
							}
						}
					}
				}
			}

			if (formData) service = await saveForm(formData, service);
			setLocation(service);
			forceSave = false;
			$isDeploymentEnabled = checkIfDeploymentEnabledServices(service);
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
	async function migrateAppwriteDB() {
		loading.verification = true;
		try {
			await post(`/services/${id}/${service.type}/migrate`, { id: service.id });
			return addToast({
				message: "Appwrite's database has been migrated.",
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.verification = false;
		}
	}
	async function changeSettings(name: any) {
		if (!$appSession.isAdmin) return;
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
				message: 'Cleared unnecessary database logs.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.cleanup = false;
		}
	}
	async function selectTag(event: any) {
		service.version = event.detail.value;
	}
	onMount(async () => {
		if (browser && window.location.hostname === 'demo.coolify.io' && !service.fqdn) {
			service.fqdn = `http://${cuid()}.demo.coolify.io`;
			// if (service.type === 'wordpress') {
			// 	service.wordpress.mysqlDatabase = 'db';
			// }
			// if (service.type === 'plausibleanalytics') {
			// 	service.plausibleAnalytics.email = 'noreply@demo.com';
			// 	service.plausibleAnalytics.username = 'admin';
			// }
			// if (service.type === 'minio') {
			// 	service.minio.apiFqdn = `http://${cuid()}.demo.coolify.io`;
			// }
			// if (service.type === 'ghost') {
			// 	service.ghost.mariadbDatabase = 'db';
			// }
			// if (service.type === 'fider') {
			// 	service.fider.emailNoreply = 'noreply@demo.com';
			// }
			// await handleSubmit();
		}
	});
</script>

<div class="w-full">
	<form id="saveForm" on:submit|preventDefault={handleSubmit}>
		<div class="mx-auto w-full">
			<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
				<div class="title font-bold pb-3 ">General</div>
				{#if $appSession.isAdmin}
					<button
						type="submit"
						class="btn btn-sm"
						class:bg-orange-600={forceSave}
						class:hover:bg-orange-400={forceSave}
						class:loading={loading.save}
						class:btn-primary={!loading.save}
						disabled={loading.save}
						>{loading.save
							? $t('forms.save')
							: forceSave
							? $t('forms.confirm_continue')
							: $t('forms.save')}</button
					>
				{/if}
				{#if service.type === 'plausibleanalytics' && $status.service.overallStatus === 'healthy'}
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
				{#if service.type === 'appwrite' && $status.service.overallStatus === 'healthy'}
					<button
						class="btn btn-sm"
						on:click|preventDefault={migrateAppwriteDB}
						disabled={loading.verification}
						class:loading={loading.verification}
						>{loading.verification
							? 'Migrating... it may take a while...'
							: "Migrate Appwrite's Database"}</button
					>
					<div>
						<DocLink url="https://appwrite.io/docs/upgrade#run-the-migration" />
					</div>
				{/if}
			</div>
		</div>

		<div class="grid grid-flow-row gap-2 px-4">
			<div class="mt-2 grid grid-cols-2 items-center">
				<label for="name">{$t('forms.name')}</label>
				<input
					name="name"
					id="name"
					class="w-full"
					disabled={!$appSession.isAdmin}
					bind:value={service.name}
					required
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="version">Version / Tag</label>
				{#if tags.tags?.length > 0}
					<div class="custom-select-wrapper w-full">
						<Select
							form="saveForm"
							containerClasses={isDisabled && containerClass()}
							{isDisabled}
							id="version"
							showIndicator={!isDisabled}
							items={[...tags.tags]}
							on:select={selectTag}
							value={service.version}
							isClearable={false}
						/>
					</div>
				{:else}
					<input class="w-full border-red-500" disabled placeholder="Error getting tags..." />
				{/if}
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

			<div class="grid grid-cols-2 items-center">
				<label for="fqdn"
					>{$t('application.url_fqdn')}
					<Explainer explanation={$t('application.https_explainer')} />
				</label>
				<CopyPasswordField
					placeholder="eg: https://coollabs.io"
					readonly={isDisabled}
					disabled={isDisabled}
					name="fqdn"
					id="fqdn"
					pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
					bind:value={service.fqdn}
					required
				/>
			</div>
			{#each Object.keys(template) as oneService}
				{#each template[oneService].fqdns as fqdn}
					<div class="grid grid-cols-2 items-center py-1">
						<label for={fqdn.name}>{fqdn.label || fqdn.name}</label>
						<CopyPasswordField
							placeholder="eg: https://coolify.io"
							readonly={isDisabled}
							disabled={isDisabled}
							required={fqdn.required}
							name={fqdn.name}
							id={fqdn.name}
							bind:value={fqdn.value}
						/>
					</div>
				{/each}
			{/each}
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

		<div class="grid grid-flow-row gap-2 px-4">
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="dualCerts"
					disabled={$status.service.isRunning || !$appSession.isAdmin}
					dataTooltip={$t('forms.must_be_stopped_to_modify')}
					bind:setting={dualCerts}
					title={$t('application.ssl_www_and_non_www')}
					description={$t('services.generate_www_non_www_ssl')}
					on:click={() => !$status.service.isRunning && changeSettings('dualCerts')}
				/>
			</div>
			{#if hostPorts.length === 0}
				<div class="grid grid-cols-2 items-center">
					<label for="exposePort"
						>Exposed Port <Explainer
							explanation={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
						/></label
					>
					<input
						class="w-full"
						readonly={isDisabled}
						disabled={isDisabled}
						name="exposePort"
						id="exposePort"
						bind:value={service.exposePort}
						placeholder="12345"
					/>
				</div>
			{/if}
		</div>
		<div class="pt-6">
			{#each Object.keys(template) as oneService}
				<div
					class="flex flex-row my-2 space-x-2 mb-6"
					class:my-6={template[oneService].environment.length > 0 &&
						template[oneService].environment.find((env) => env.main === oneService)}
					class:border-b={template[oneService].environment.length > 0 &&
						template[oneService].environment.find((env) => env.main === oneService)}
					class:border-coolgray-500={template[oneService].environment.length > 0 &&
						template[oneService].environment.find((env) => env.main === oneService)}
				>
					<div class="title font-bold pb-3 capitalize">
						{template[oneService].name ||
							oneService.replace(`${id}-`, '').replace(id, service.type)}
					</div>
					<ServiceStatus id={oneService} />
				</div>
				<div class="grid grid-flow-row gap-2 px-4">
					{#if template[oneService].environment.length > 0}
						{#each template[oneService].environment as variable}
							{#if variable.main === oneService}
								<div class="grid grid-cols-2 items-center gap-2">
									<label class="h-10" for={variable.name}
										>{variable.label || variable.name}
										{#if variable.description}
											<Explainer explanation={variable.description} />
										{/if}</label
									>
									{#if variable.defaultValue === '$$generate_fqdn'}
										<CopyPasswordField
											disabled
											readonly
											name={variable.name}
											id={variable.name}
											value={service.fqdn}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else if variable.defaultValue === '$$generate_fqdn_slash'}
										<CopyPasswordField
											disabled
											readonly
											name={variable.name}
											id={variable.name}
											value={service.fqdn + '/' || ''}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else if variable.defaultValue === '$$generate_domain'}
										<CopyPasswordField
											disabled
											readonly
											name={variable.name}
											id={variable.name}
											value={getDomain(service.fqdn) || ''}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else if variable.defaultValue === '$$generate_network'}
										<CopyPasswordField
											disabled
											readonly
											name={variable.name}
											id={variable.name}
											value={service.destinationDocker.network}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else if variable.defaultValue === 'true' || variable.defaultValue === 'false'}
										{#if variable.value === 'true' || variable.value === 'false' || variable.value === 'invite_only'}
											<select
												class="w-full font-normal"
												readonly={isDisabled}
												disabled={isDisabled}
												id={variable.name}
												name={variable.name}
												bind:value={variable.value}
												form="saveForm"
												placeholder={variable.placeholder}
												required={variable?.required}
											>
												<option value="true">enabled</option>
												<option value="false">disabled</option>
												{#if service.type.startsWith('plausibleanalytics') && variable.id == 'config_disable_registration'}
													<option value="invite_only">invite_only</option>
												{/if}
											</select>
										{:else}
											<select
												class="w-full font-normal"
												readonly={isDisabled}
												disabled={isDisabled}
												id={variable.name}
												name={variable.name}
												bind:value={variable.defaultValue}
												form="saveForm"
												placeholder={variable.placeholder}
												required={variable?.required}
											>
												<option value="true">true</option>
												<option value="false">false</option>
											</select>
										{/if}
									{:else if variable.defaultValue === '$$generate_password'}
										<CopyPasswordField
											isPasswordField
											readonly
											disabled
											name={variable.name}
											id={variable.name}
											value={variable.value}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else if variable.type === 'textarea'}
										<textarea
											class="w-full"
											value={variable.value}
											readonly={isDisabled}
											disabled={isDisabled}
											class:resize-none={$status.service.overallStatus === 'healthy'}
											rows="5"
											name={variable.name}
											id={variable.name}
											placeholder={variable.placeholder}
											required={variable?.required}
										/>
									{:else}
										<CopyPasswordField
											isPasswordField={variable.id.startsWith('secret')}
											required={variable?.required}
											readonly={variable.readOnly || isDisabled}
											disabled={variable.readOnly || isDisabled}
											name={variable.name}
											id={variable.name}
											value={variable.value}
											placeholder={variable.placeholder}
										/>
									{/if}
								</div>
							{/if}
						{/each}
						{#if template[oneService].name.toLowerCase() === 'wordpress' && service.type.startsWith('wordpress')}
							<Wordpress {service} />
						{/if}
					{/if}
				</div>
			{/each}
		</div>
	</form>
</div>
