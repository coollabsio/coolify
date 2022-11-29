<script lang="ts">
	export let payload: any;

	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import Setting from '$lib/components/Setting.svelte';
	import { appSession } from '$lib/store';
	import { t } from '$lib/translations';

	const from = $page.url.searchParams.get('from');
	let loading = false;

	async function handleSubmit() {
		if (loading) return;
		try {
			loading = true;
			await post(`/destinations/check`, { network: payload.network });
			const { id } = await post(`/destinations/new`, {
				...payload
			});
			return await goto(from || `/destinations/${id}`);
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="flex justify-center px-6 pb-8">
	<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
		<div
			class="flex items-start lg:items-center space-x-0 lg:space-x-4 pb-5 flex-col lg:flex-row space-y-4 lg:space-y-0"
		>
			<div class="title font-bold">{$t('forms.configuration')}</div>
			<button
				type="submit"
				class="btn btn-sm bg-destinations w-full lg:w-fit"
				class:loading
				disabled={loading}
				>{loading
					? payload.isCoolifyProxyUsed
						? $t('destination.new.saving_and_configuring_proxy')
						: $t('forms.saving')
					: $t('forms.save')}</button
			>
		</div>
		<div class="mt-2 grid grid-cols-2 items-center lg:pl-10">
			<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
			<input required name="name" placeholder={$t('forms.name')} bind:value={payload.name} />
		</div>

		<div class="grid grid-cols-2 items-center lg:pl-10">
			<label for="engine" class="text-base font-bold text-stone-100">{$t('forms.engine')}</label>
			<input
				required
				name="engine"
				placeholder="{$t('forms.eg')}: /var/run/docker.sock"
				bind:value={payload.engine}
			/>
		</div>
		<div class="grid grid-cols-2 items-center lg:pl-10">
			<label for="network" class="text-base font-bold text-stone-100">{$t('forms.network')}</label>
			<input
				required
				name="network"
				placeholder="{$t('forms.default')}: coolify"
				bind:value={payload.network}
			/>
		</div>
		{#if $appSession.teamId === '0'}
			<div class="grid grid-cols-2 items-center lg:pl-10">
				<Setting
					id="changeProxySetting"
					bind:setting={payload.isCoolifyProxyUsed}
					on:click={() => (payload.isCoolifyProxyUsed = !payload.isCoolifyProxyUsed)}
					title={$t('destination.use_coolify_proxy')}
					description={'This will install a proxy on the destination to allow you to access your applications and services without any manual configuration.'}
				/>
			</div>
		{/if}
	</form>
</div>
