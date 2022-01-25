<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
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
	import { toast } from '@zerodevx/svelte-toast';
	import { del, post } from '$lib/api';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let fqdn = settings.fqdn;

	async function removeFqdn() {
		if (fqdn) {
			try {
				await del(`/settings.json`, { fqdn });
				return window.location.reload();
			} catch ({ error }) {
				return errorNotification(error);
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
			if (fqdn) {
				await post(`/settings/check.json`, { fqdn });
				await post(`/settings.json`, { fqdn });
				return window.location.reload();
			}
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Settings</div>
</div>
{#if $session.teamId === '0'}
	<div class="max-w-2xl mx-auto">
		<form on:submit|preventDefault={handleSubmit}>
			<div class="font-bold flex space-x-1 py-5 px-6">
				<div class="text-xl tracking-tight mr-4">Global Settings</div>
				<button type="submit" class="mx-2 bg-green-600 hover:bg-green-500">Save</button>
				{#if !!fqdn}
					<button on:click|preventDefault={removeFqdn} class="bg-red-600 hover:bg-red-500"
						>Remove Domain</button
					>
				{/if}
			</div>
			<div class="px-4 sm:px-6">
				<div class="py-4 flex space-x-4 px-4">
					<p class="text-base font-bold text-stone-100">Domain (FQDN)</p>

					<div class="justify-center text-center space-y-2">
						<input
							bind:value={fqdn}
							readonly={!$session.isAdmin}
							name="fqdn"
							id="fqdn"
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="eg: https://coolify.io"
							required
						/>
						<Explainer
							text="Set the fully qualified domain name for your Coolify instance. If you specify <span class='text-green-600'>https</span>, it will be accessible only over https. SSL certificate will be generated for you."
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
	</div>
{/if}
