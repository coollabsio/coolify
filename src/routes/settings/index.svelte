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
	import Setting from './_Setting.svelte';

	let isRegistrationEnabled =
		settings.find((setting) => setting.name === 'isRegistrationEnabled').value === 'true';

	async function changeSettings(name) {
		const form = new FormData();
		form.append('name', name);

		if (name === 'isRegistrationEnabled') {
			isRegistrationEnabled = !isRegistrationEnabled;
			form.append('value', isRegistrationEnabled.toString());
		}
		try {
			await fetch('/settings.json', {
				method: 'POST',
				body: form
			});
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
