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

	let isRegistrationEnabled =
		settings.find((setting) => setting.name === 'isRegistrationEnabled')?.value === 'true';

	let domain = settings.find((setting) => setting.name === 'domain')?.value;
	let domainConfigured = !!domain;
	async function removeDomain(name) {
		if (domainConfigured) {
			const form = new FormData();
			form.append('name', name);

			try {
				await fetch('/settings.json', {
					method: 'DELETE',
					body: form
				});
				window.location.reload();
			} catch (e) {
				console.error(e);
			}
		}
	}
	async function changeSettings(name) {
		const form = new FormData();
		form.append('name', name);

		if (name === 'isRegistrationEnabled') {
			isRegistrationEnabled = !isRegistrationEnabled;
			form.append('value', isRegistrationEnabled.toString());
		}
		if (name === 'domain') {
			form.append('value', domain.toString());
		}
		try {
			await fetch('/settings.json', {
				method: 'POST',
				body: form
			});
			window.location.reload();
		} catch (e) {
			console.error(e);
		}
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Settings</div>
</div>
{#if $session.teamId === '0'}
	<div class="max-w-2xl mx-auto">
		<div class="font-bold flex space-x-1 py-5 px-6">
			<div class="text-xl tracking-tight mr-4">Global Settings</div>
		</div>
		<div class="px-4 sm:px-6">
			<div class="py-4 flex items-center">
				<form class="flex" on:submit|preventDefault={() => changeSettings('domain')}>
					<div class="flex flex-col">
						<div class="flex">
							<p class="text-base font-bold text-warmGray-100">Domain</p>
							<button type="submit" class="mx-2 bg-green-600 hover:bg-green-500">Save</button>
						</div>
						<Explainer text="Set the domain that you could use to access Coolify." />
					</div>

					<div class="justify-center text-center space-y-2">
						<input
							bind:value={domain}
							readonly={!$session.isAdmin}
							name="domain"
							id="domain"
							pattern="^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="eg: coolify.io"
							required
						/>

						{#if domainConfigured}
							<button on:click|preventDefault={() => removeDomain('domain')} class="bg-red-600 hover:bg-red-500">Remove domain</button>
						{/if}
					</div>
				</form>
			</div>
			<ul class="mt-2 divide-y divide-warmGray-800">
				<Setting
					bind:setting={isRegistrationEnabled}
					title="Registration allowed?"
					description="Allow further registrations to the application. <br>It's turned off after the first registration. "
					on:click={() => changeSettings('isRegistrationEnabled')}
				/>
			</ul>
		</div>
	</div>
{/if}
