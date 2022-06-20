<script lang="ts">
	import { browser } from '$app/env';

	export let service;
	export let isRunning;
	export let readOnly;
	export let settings;

	import { page, session } from '$app/stores';
	import { post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { t } from '$lib/translations';
	import { toast } from '@zerodevx/svelte-toast';
	import cuid from 'cuid';
	import { onMount } from 'svelte';
	import Fider from './_Fider.svelte';
	import Ghost from './_Ghost.svelte';
	import Hasura from './_Hasura.svelte';
	import MeiliSearch from './_MeiliSearch.svelte';
	import MinIo from './_MinIO.svelte';
	import PlausibleAnalytics from './_PlausibleAnalytics.svelte';
	import Umami from './_Umami.svelte';
	import VsCodeServer from './_VSCodeServer.svelte';
	import Wordpress from './_Wordpress.svelte';

	const { id } = $page.params;

	let loading = false;
	let loadingVerification = false;
	let dualCerts = service.dualCerts;

	async function handleSubmit() {
		if (loading) return;
		loading = true;
		try {
			await post(`/services/${id}/check.json`, {
				fqdn: service.fqdn,
				otherFqdns: service.minio?.apiFqdn ? [service.minio?.apiFqdn] : [],
				exposePort: service.exposePort
			});
			await post(`/services/${id}/${service.type}.json`, { ...service });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
	async function setEmailsToVerified() {
		loadingVerification = true;
		try {
			await post(`/services/${id}/${service.type}/activate.json`, { id: service.id });
			toast.push(t.get('services.all_email_verified'));
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loadingVerification = false;
		}
	}
	async function changeSettings(name) {
		try {
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			await post(`/services/${id}/settings.json`, { dualCerts });
			return toast.push(t.get('application.settings_saved'));
		} catch ({ error }) {
			return errorNotification(error);
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

<div class="mx-auto max-w-4xl px-6 pb-12">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="title">{$t('general')}</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-pink-600={!loading}
					class:hover:bg-pink-500={!loading}
					disabled={loading}>{loading ? $t('forms.saving') : $t('forms.save')}</button
				>
			{/if}
			{#if service.type === 'plausibleanalytics' && isRunning}
				<button on:click|preventDefault={setEmailsToVerified} disabled={loadingVerification}
					>{loadingVerification
						? $t('forms.verifying')
						: $t('forms.verify_emails_without_smtp')}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2">
			{#if service.type === 'minio' && !service.minio.apiFqdn && isRunning}
				<div class="text-center">
					<span class="font-bold text-red-500">IMPORTANT!</span> There was a small modification with
					Minio in the latest version of Coolify. Now you can separate the Console URL from the API URL,
					so you could use both through SSL. But this proccess cannot be done automatically, so you have
					to stop your Minio instance, configure the new domain and start it back. Sorry for any inconvenience.
				</div>
			{/if}
			<div class="mt-2 grid grid-cols-2 items-center px-10">
				<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
				<div>
					<input
						readonly={!$session.isAdmin}
						name="name"
						id="name"
						bind:value={service.name}
						required
					/>
				</div>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="version" class="text-base font-bold text-stone-100">Version / Tag</label>
				<a
					href={$session.isAdmin && !isRunning
						? `/services/${id}/configuration/version?from=/services/${id}`
						: ''}
					class="no-underline"
				>
					<input
						value={service.version}
						id="service"
						disabled={isRunning}
						class:cursor-pointer={!isRunning}
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="destination" class="text-base font-bold text-stone-100"
					>{$t('application.destination')}</label
				>
				<div>
					{#if service.destinationDockerId}
						<div class="no-underline">
							<input
								value={service.destinationDocker.name}
								id="destination"
								disabled
								class="bg-transparent "
							/>
						</div>
					{/if}
				</div>
			</div>
			{#if service.type === 'minio'}
				<div class="grid grid-cols-2 px-10">
					<div class="flex-col ">
						<label for="fqdn" class="pt-2 text-base font-bold text-stone-100">Console URL</label>
					</div>

					<CopyPasswordField
						placeholder="eg: https://console.min.io"
						readonly={!$session.isAdmin && !isRunning}
						disabled={!$session.isAdmin || isRunning}
						name="fqdn"
						id="fqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.fqdn}
						required
					/>
				</div>
				<div class="grid grid-cols-2 px-10">
					<div class="flex-col ">
						<label for="apiFqdn" class="pt-2 text-base font-bold text-stone-100">API URL</label>
						<Explainer text={$t('application.https_explainer')} />
					</div>

					<CopyPasswordField
						placeholder="eg: https://min.io"
						readonly={!$session.isAdmin && !isRunning}
						disabled={!$session.isAdmin || isRunning}
						name="apiFqdn"
						id="apiFqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.minio.apiFqdn}
						required
					/>
				</div>
			{:else}
				<div class="grid grid-cols-2 px-10">
					<div class="flex-col ">
						<label for="fqdn" class="pt-2 text-base font-bold text-stone-100"
							>{$t('application.url_fqdn')}</label
						>
						<Explainer text={$t('application.https_explainer')} />
					</div>

					<CopyPasswordField
						placeholder="eg: https://analytics.coollabs.io"
						readonly={!$session.isAdmin && !isRunning}
						disabled={!$session.isAdmin || isRunning}
						name="fqdn"
						id="fqdn"
						pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						bind:value={service.fqdn}
						required
					/>
				</div>
			{/if}

			<div class="grid grid-cols-2 items-center px-10">
				<Setting
					disabled={isRunning}
					dataTooltip={$t('forms.must_be_stopped_to_modify')}
					bind:setting={dualCerts}
					title={$t('application.ssl_www_and_non_www')}
					description={$t('services.generate_www_non_www_ssl')}
					on:click={() => !isRunning && changeSettings('dualCerts')}
				/>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="exposePort" class="text-base font-bold text-stone-100">Exposed Port</label>
				<input
					readonly={!$session.isAdmin && !isRunning}
					disabled={!$session.isAdmin || isRunning}
					name="exposePort"
					id="exposePort"
					bind:value={service.exposePort}
					placeholder="12345"
				/>
				<Explainer
					text={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
				/>
			</div>

			{#if service.type === 'plausibleanalytics'}
				<PlausibleAnalytics bind:service {isRunning} {readOnly} />
			{:else if service.type === 'minio'}
				<MinIo {service} />
			{:else if service.type === 'vscodeserver'}
				<VsCodeServer {service} />
			{:else if service.type === 'wordpress'}
				<Wordpress bind:service {isRunning} {readOnly} {settings} />
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
			{/if}
		</div>
	</form>
</div>
