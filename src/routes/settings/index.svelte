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
	import { del, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { browser } from '$app/env';
	import { getDomain } from '$lib/components/common';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let fqdn = settings.fqdn;
	let isFqdnSet = settings.fqdn;
	let loading = {
		save: false,
		remove: false
	};

	async function removeFqdn() {
		if (fqdn) {
			loading.remove = true;
			try {
				await del(`/settings.json`, { fqdn });
				return window.location.reload();
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
			return await post(`/settings.json`, { isRegistrationEnabled });
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			loading.save = true;
			if (fqdn) {
				await post(`/settings/check.json`, { fqdn });
				await post(`/settings.json`, { fqdn });
				return window.location.reload();
			}
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Settings</div>
</div>
{#if $session.teamId === '0'}
	<div class="mx-auto max-w-2xl">
		<form on:submit|preventDefault={handleSubmit}>
			<div class="flex space-x-1 p-6 font-bold">
				<div class="mr-4 text-xl tracking-tight">Global Settings</div>
				<button
					type="submit"
					disabled={loading.save}
					class:bg-green-600={!loading.save}
					class:hover:bg-green-500={!loading.save}
					class="mx-2 ">{loading.save ? 'Saving...' : 'Save'}</button
				>
				{#if isFqdnSet}
					<button
						on:click|preventDefault={removeFqdn}
						disabled={loading.remove}
						class:bg-red-600={!loading.remove}
						class:hover:bg-red-500={!loading.remove}
						>{loading.remove ? 'Removing...' : 'Remove domain'}</button
					>
				{/if}
			</div>
			<div class="px-4 sm:px-6">
				<div class="flex space-x-4 py-4 px-4">
					<p class="pt-2 text-base font-bold text-stone-100">Domain (FQDN)</p>
					<div class="justify-center text-center">
						<input
							bind:value={fqdn}
							readonly={!$session.isAdmin || isFqdnSet}
							disabled={!$session.isAdmin || isFqdnSet}
							name="fqdn"
							id="fqdn"
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="eg: https://coolify.io"
							required
						/>
						<Explainer
							text="Set the fully qualified domain name for your Coolify instance. <br>If you specify <span class='text-green-600'>https</span>, it will be accessible only over https. <br>SSL certificate will be generated for you."
						/>
					</div>
				</div>
				<ul class="mt-2 divide-y divide-stone-800">
					<Setting
						bind:setting={isRegistrationEnabled}
						title="Registration allowed?"
						description="Allow further registrations to the application. <br>It's turned off after the first registration. "
						on:click={() => changeSettings('isRegistrationEnabled')}
					/>
				</ul>
			</div>
		</form>
		<div class="mx-auto max-w-4xl px-6">
			<div class="flex space-x-1 pt-5 font-bold">
				<div class="mr-4 text-xl tracking-tight">HAProxy Settings</div>
			</div>
			<Explainer
				text={`Credentials for <a class="text-white" href=${
					fqdn ? getDomain(fqdn) : browser && 'http://' + window.location.hostname + ':8404'
				} target="_blank">stats</a> page.`}
			/>

			<div class="grid grid-cols-3 items-center px-4 pt-5">
				<label for="proxyUser">User</label>

				<div class="col-span-2 ">
					<CopyPasswordField
						readonly
						disabled
						id="proxyUser"
						name="proxyUser"
						value={settings.proxyUser}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center px-4">
				<label for="proxyPassword">Password</label>
				<div class="col-span-2 ">
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
	</div>
{/if}
