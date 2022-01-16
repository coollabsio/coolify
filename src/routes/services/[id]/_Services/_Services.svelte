<script lang="ts">
	export let service;
	import { page, session } from '$app/stores';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { enhance, errorNotification } from '$lib/form';
	import MinIo from './_MinIO.svelte';
	import NocoDb from './_NocoDB.svelte';
	import PlausibleAnalytics from './_PlausibleAnalytics.svelte';
	import VsCodeServer from './_VSCodeServer.svelte';
	import Wordpress from './_Wordpress.svelte';

	const { id } = $page.params;
	let loading = false;
	let domainEl;
</script>

<div class="max-w-4xl mx-auto px-6">
	<form
		action="/services/{id}/{service.type}.json"
		use:enhance={{
			beforeSubmit: async () => {
				const form = new FormData();
				form.append('domain', domainEl.value);
				const response = await fetch(`/services/${id}/check.json`, {
					method: 'POST',
					headers: {
						accept: 'application/json'
					},
					body: form
				});
				if (!response.ok) {
					const error = await response.json();
					errorNotification(error.message || error);
					throw new Error(error.message || error);
				}
			},
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 200);
			},
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
		method="post"
		class="py-4"
	>
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Configurations</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-pink-600={!loading}
					class:hover:bg-pink-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center">
				<label for="name">Name</label>
				<div class="col-span-2 ">
					<input readonly={!$session.isAdmin} name="name" id="name" value={service.name} required />
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="domain">Domain</label>
				<div class="col-span-2 ">
					<input placeholder="eg: analytics.coollabs.io" readonly={!$session.isAdmin} name="domain" id="domain" bind:this={domainEl} value={service.domain} required />
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="destination">Destination</label>
				<div class="col-span-2">
					{#if service.destinationDockerId}
						<div class="no-underline">
							<input
								value={service.destinationDocker.name}
								id="destination"
								disabled
								class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
							/>
						</div>
					{/if}
				</div>
			</div>

			{#if service.type === 'plausibleanalytics'}
				<PlausibleAnalytics  {service}/>
			{:else if service.type === 'nocodb'}
				<NocoDb {service}/>
			{:else if service.type === 'minio'}
				<MinIo {service}/>
			{:else if service.type === 'vscodeserver'}
				<VsCodeServer {service}/>
			{:else if service.type === 'wordpress'}
				<Wordpress {service}/>
			{/if}
		</div>
	</form>
	<!-- <div class="font-bold flex space-x-1 pb-5">
		<div class="text-xl tracking-tight mr-4">Features</div>
	</div>
	<div class="px-4 sm:px-6 pb-10">
		<ul class="mt-2 divide-y divide-warmGray-800">
			<Setting
				bind:setting={isPublic}
				on:click={() => changeSettings('isPublic')}
				title="Set it public"
				description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
			/>
		</ul>
	</div> -->
</div>
