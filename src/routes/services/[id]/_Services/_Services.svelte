<script lang="ts">
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
	import { toast } from '@zerodevx/svelte-toast';
	import Ghost from './_Ghost.svelte';
	import MeiliSearch from './_MeiliSearch.svelte';
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
			toast.push('All email verified. You can login now.');
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
			return toast.push('Settings saved.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="mx-auto max-w-4xl px-6 pb-12">
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="title">General</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-pink-600={!loading}
					class:hover:bg-pink-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
			{#if service.type === 'plausibleanalytics' && isRunning}
				<button on:click|preventDefault={setEmailsToVerified} disabled={loadingVerification}
					>{loadingVerification ? 'Verifying' : 'Verify emails without SMTP'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2">
			<div class="mt-2 grid grid-cols-2 items-center px-10">
				<label for="name" class="text-base font-bold text-stone-100">Name</label>
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
					href={$session.isAdmin
						? `/services/${id}/configuration/version?from=/services/${id}`
						: ''}
					class="no-underline"
				>
					<input
						value={service.version}
						id="service"
						disabled
						class="cursor-pointer hover:bg-coolgray-500"
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<label for="destination" class="text-base font-bold text-stone-100">Destination</label>
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
					<label for="fqdn" class="pt-2 text-base font-bold text-stone-100">URL (FQDN)</label>
					<Explainer
						text="If you specify <span class='text-pink-600 font-bold'>https</span>, the application will be accessible only over https. SSL certificate will be generated for you.<br>If you specify <span class='text-pink-600 font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the url, you must first stop the application."
					/>
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
					dataTooltip="Must be stopped to modify."
					bind:setting={dualCerts}
					title="Generate SSL for www and non-www?"
					description="It will generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-pink-600'>both DNS entries</span> set in advance.<br><br>Service needs to be restarted."
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
				<Wordpress bind:service {isRunning} {readOnly} {settings} />
			{:else if service.type === 'ghost'}
				<Ghost bind:service {readOnly} />
			{:else if service.type === 'meilisearch'}
				<MeiliSearch bind:service />
			{/if}
		</div>
	</form>
</div>
