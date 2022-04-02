<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { service } = stuff;
		if (service?.version && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/services/${params.id}`
			};
		}
		const endpoint = `/services/${params.id}/configuration/version.json`;
		const res = await fetch(endpoint);

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
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let versions;
	async function handleSubmit(version) {
		try {
			await post(`/services/${id}/configuration/version.json`, { version });
			return await goto(from || `/services/${id}`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('forms.select_a_service_version')}</div>
</div>

<div class="flex flex-wrap justify-center">
	{#each versions as version}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(version)}>
				<button type="submit" class="box-selection text-xl font-bold hover:bg-pink-600"
					>{version}</button
				>
			</form>
		</div>
	{/each}
</div>
