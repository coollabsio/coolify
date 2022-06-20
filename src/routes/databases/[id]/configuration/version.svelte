<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { database } = stuff;
		if (database?.version && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/databases/${params.id}`
			};
		}
		const endpoint = `/databases/${params.id}/configuration/version.json`;
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
	import { enhance, errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let versions;
	async function handleSubmit(version) {
		try {
			await post(`/databases/${id}/configuration/version.json`, { version });
			return await goto(from || `/databases/${id}/configuration/destination`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('database.select_database_version')}</div>
</div>
{#if from}
	<div class="pb-10 text-center">
		Warning: you are about to change the version of this database.<br />This could cause problem
		after you restart the database,
		<span class="font-bold text-pink-600">like losing your data, incompatibility issues, etc</span
		>.<br />Only do if you know what you are doing!
	</div>
{/if}
<div class="flex flex-wrap justify-center">
	{#each versions as version}
		<div class="p-2">
			<form on:submit|preventDefault={() => handleSubmit(version)}>
				<button type="submit" class="box-selection text-xl font-bold hover:bg-purple-700"
					>{version}</button
				>
			</form>
		</div>
	{/each}
</div>
