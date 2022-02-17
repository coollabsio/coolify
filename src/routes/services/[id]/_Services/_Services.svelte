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
	import { toast } from '@zerodevx/svelte-toast';
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

<div class="mx-auto max-w-4xl px-6">
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
				<button
					on:click|preventDefault={setEmailsToVerified}
					class:bg-pink-600={!loadingVerification}
					class:hover:bg-pink-500={!loadingVerification}
					disabled={loadingVerification}
					>{loadingVerification ? 'Verifying' : 'Verify emails without SMTP'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2">
			<div class="mt-2 grid grid-cols-2 items-center px-10">
				<label for="name">Name</label>
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
				<label for="destination">Destination</label>
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
				<label for="fqdn" class="pt-2">Domain (FQDN)</label>
				<div>
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
					<Explainer
						text="If you specify <span class='text-pink-600 font-bold'>https</span>, the application will be accessible only over https. SSL certificate will be generated for you.<br>If you specify <span class='text-pink-600 font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the domain, you must first stop the application."
					/>
				</div>
			</div>
			<div class="grid grid-cols-2 items-center px-10">
				<Setting
					bind:setting={dualCerts}
					title="Generate SSL for www and non-www?"
					description="It will generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-pink-600'>both DNS entries</span> set in advance.<br><br>Service needs to be restarted."
					on:click={() => changeSettings('dualCerts')}
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
