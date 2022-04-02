<script lang="ts">
	export let service;
	export let isRunning;
	export let readOnly;

	import { page, session } from '$app/stores';
	import { post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { t } from '$lib/translations';
	import { toast } from '@zerodevx/svelte-toast';
	import Ghost from './_Ghost.svelte';
	import MinIo from './_MinIO.svelte';
	import PlausibleAnalytics from './_PlausibleAnalytics.svelte';
	import VsCodeServer from './_VSCodeServer.svelte';
	import Wordpress from './_Wordpress.svelte';

	const { id } = $page.params;

	let loading = false;
	let loadingVerification = false;
	let dualCerts = service.dualCerts;

	async function handleSubmit() {
		loading = true;
		try {
			await post(`/services/${id}/check.json`, { fqdn: service.fqdn });
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
			<div class="grid grid-cols-2 px-10">
				<div class="flex-col ">
					<label for="fqdn" class="pt-2 text-base font-bold text-stone-100"
						>{$t('application.domain_fqdn')}</label
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
			{#if service.type === 'plausibleanalytics'}
				<PlausibleAnalytics bind:service {readOnly} />
			{:else if service.type === 'minio'}
				<MinIo {service} />
			{:else if service.type === 'vscodeserver'}
				<VsCodeServer {service} />
			{:else if service.type === 'wordpress'}
				<Wordpress bind:service {isRunning} {readOnly} />
			{:else if service.type === 'ghost'}
				<Ghost bind:service {readOnly} />
			{/if}
		</div>
	</form>
	<!-- <div class="font-bold flex space-x-1 pb-5">
		<div class="text-xl tracking-tight mr-4">Features</div>
	</div>
	<div class="px-4 sm:px-6 pb-10">
		<ul class="mt-2 divide-y divide-stone-800">
			<Setting
				bind:setting={isPublic}
				on:click={() => changeSettings('isPublic')}
				title="Set it public"
				description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
			/>
		</ul>
	</div> -->
</div>
