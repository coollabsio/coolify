<script lang="ts">
	import { goto } from '$app/navigation';

	export let payload;

	import { post } from '$lib/api';
	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import { errorNotification } from '$lib/form';
	import { t } from '$lib/translations';

	let loading = false;

	async function handleSubmit() {
		try {
			const { id } = await post('/new/destination/docker.json', {
				...payload
			});
			return await goto(`/destinations/${id}`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex justify-center px-6 pb-8">
	<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
		<div class="flex items-center space-x-2 pb-5">
			<div class="title font-bold">{$t('forms.configuration')}</div>
			<button
				type="submit"
				class:bg-sky-600={!loading}
				class:hover:bg-sky-500={!loading}
				disabled={loading}
				>{loading
					? payload.isCoolifyProxyUsed
						? $t('destination.new.saving_and_configuring_proxy')
						: $t('forms.saving')
					: $t('forms.save')}</button
			>
		</div>
		<div class="mt-2 grid grid-cols-2 items-center px-10">
			<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
			<input required name="name" placeholder={$t('forms.name')} bind:value={payload.name} />
		</div>

		<div class="grid grid-cols-2 items-center px-10">
			<label for="ipAddress" class="text-base font-bold text-stone-100"
				>{$t('forms.ip_address')}</label
			>
			<input
				required
				name="ipAddress"
				placeholder="{$t('forms.eg')}: 192.168..."
				bind:value={payload.ipAddress}
			/>
		</div>

		<div class="grid grid-cols-2 items-center px-10">
			<label for="user" class="text-base font-bold text-stone-100">{$t('forms.user')}</label>
			<input required name="user" placeholder="{$t('forms.eg')}: root" bind:value={payload.user} />
		</div>

		<div class="grid grid-cols-2 items-center px-10">
			<label for="port" class="text-base font-bold text-stone-100">{$t('forms.port')}</label>
			<input required name="port" placeholder="{$t('forms.eg')}: 22" bind:value={payload.port} />
		</div>
		<div class="grid grid-cols-2 items-center px-10">
			<label for="sshPrivateKey" class="text-base font-bold text-stone-100"
				>{$t('forms.ssh_private_key')}</label
			>
			<textarea
				rows="10"
				class="resize-none"
				required
				name="sshPrivateKey"
				placeholder="{$t('forms.eg')}: -----BEGIN...."
				bind:value={payload.sshPrivateKey}
			/>
		</div>

		<div class="grid grid-cols-2 items-center px-10">
			<label for="network" class="text-base font-bold text-stone-100">{$t('forms.network')}</label>
			<input
				required
				name="network"
				placeholder="{$t('forms.default')}: coolify"
				bind:value={payload.network}
			/>
		</div>
		<div class="grid grid-cols-2 items-center">
			<Setting
				bind:setting={payload.isCoolifyProxyUsed}
				on:click={() => (payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed)}
				title={$t('destination.use_coolify_proxy')}
				description={$t('destination.new.install_proxy')}
			/>
		</div>
	</form>
</div>
