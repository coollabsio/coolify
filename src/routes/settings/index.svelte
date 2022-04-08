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
	import { del, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { browser } from '$app/env';
	import { getDomain } from '$lib/components/common';
	import { toast } from '@zerodevx/svelte-toast';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;

	let minPort = settings.minPort;
	let maxPort = settings.maxPort;

	let fqdn = settings.fqdn;
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
			await post(`/settings.json`, { isRegistrationEnabled, dualCerts });
			return toast.push('Settings saved.');
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			loading.save = true;
			if (fqdn !== settings.fqdn) {
				await post(`/settings/check.json`, { fqdn });
				await post(`/settings.json`, { fqdn });
				return window.location.reload();
			}
			if (minPort !== settings.minPort || maxPort !== settings.maxPort) {
				await post(`/settings.json`, { minPort, maxPort });
				settings.minPort = minPort;
				settings.maxPort = maxPort;
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
	<div class="mx-auto max-w-4xl px-6">
		<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
			<div class="flex space-x-1 pb-6">
				<div class="title font-bold">Global Settings</div>
				<button
					type="submit"
					disabled={loading.save}
					class:bg-yellow-500={!loading.save}
					class:hover:bg-yellow-400={!loading.save}
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
			<div class="grid grid-flow-row gap-2 px-10">
				<div class="grid grid-cols-2 items-start">
					<div class="flex-col">
						<div class="pt-2 text-base font-bold text-stone-100">URL (FQDN)</div>
						<Explainer
							text="If you specify <span class='text-yellow-500 font-bold'>https</span>, Coolify will be accessible only over https. SSL certificate will be generated for you.<br>If you specify <span class='text-yellow-500 font-bold'>www</span>, Coolify will be redirected (302) from non-www and vice versa."
						/>
					</div>
					<div class="justify-start text-left">
						<input
							bind:value={fqdn}
							readonly={!$session.isAdmin || isFqdnSet}
							disabled={!$session.isAdmin || isFqdnSet}
							name="fqdn"
							id="fqdn"
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="eg: https://coolify.io"
						/>
					</div>
				</div>
				<div class="grid grid-cols-2 items-start py-6">
					<div class="flex-col">
						<div class="pt-2 text-base font-bold text-stone-100">Public Port Range</div>
						<Explainer
							text="Ports used to expose databases/services/internal services.<br> Add them to your firewall (if applicable).<br><br>You can specify a range of ports, eg: <span class='text-yellow-500 font-bold'>9000-9100</span>"
						/>
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
						dataTooltip="Must remove the domain before you can change this setting."
						disabled={isFqdnSet}
						bind:setting={dualCerts}
						title="Generate SSL for www and non-www?"
						description="It will generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-yellow-500'>both DNS entries</span> set in advance.<br><br>Useful if you expect to have visitors on both."
						on:click={() => !isFqdnSet && changeSettings('dualCerts')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						bind:setting={isRegistrationEnabled}
						title="Registration allowed?"
						description="Allow further registrations to the application. <br>It's turned off after the first registration. "
						on:click={() => changeSettings('isRegistrationEnabled')}
					/>
				</div>
			</div>
		</form>
		<div class="flex space-x-1 pt-6 font-bold">
			<div class="title">Coolify Proxy Settings</div>
		</div>
		<Explainer
			text={`Credentials for <a class="text-white font-bold" href=${
				fqdn
					? `http://${settings.proxyUser}:${settings.proxyPassword}@` + getDomain(fqdn) + ':8404'
					: browser &&
					  `http://${settings.proxyUser}:${settings.proxyPassword}@` +
							window.location.hostname +
							':8404'
			} target="_blank">stats</a> page.`}
		/>
		<div class="space-y-2 px-10 py-5">
			<div class="grid grid-cols-2 items-center">
				<label for="proxyUser">User</label>
				<CopyPasswordField
					readonly
					disabled
					id="proxyUser"
					name="proxyUser"
					value={settings.proxyUser}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="proxyPassword">Password</label>
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
{/if}
