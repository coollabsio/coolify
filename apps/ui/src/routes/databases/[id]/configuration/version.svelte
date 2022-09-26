<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { database } = stuff;
			if (database?.version && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/database/${params.id}`
				};
			}
			const response = await get(`/databases/${params.id}/configuration/version`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let versions: any;

	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	async function handleSubmit(version: any) {
		try {
			await post(`/databases/${id}/configuration/version`, { version });
			return await goto(from || `/databases/${id}/configuration/destination`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

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
