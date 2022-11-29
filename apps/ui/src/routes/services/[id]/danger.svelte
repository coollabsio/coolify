<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/services/${params.id}`);
			return {
				props: {
					application: stuff.application,
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
	export let service: any;
	import { page } from '$app/stores';
	import { del, get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { appSession, status } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	let forceDelete = false;
	async function deleteService() {
		const sure = confirm($t('application.confirm_to_delete', { name: service.name }));
		if (sure) {
			$status.service.initialLoading = true;
			try {
				if (service.type && $status.service.overallStatus !== 'stopped') {
					await post(`/services/${service.id}/stop`, {});
				}
				await del(`/services/${service.id}`, { id: service.id });
				return await goto('/');
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.service.initialLoading = false;
			}
		}
	}
</script>

<div class="mx-auto w-full">
	<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
		<div class="title font-bold pb-3">Danger Zone</div>
	</div>
	<button
		id="forcedelete"
		on:click={() => deleteService()}
		type="submit"
		disabled={!$appSession.isAdmin}
		class:bg-red-600={$appSession.isAdmin}
		class:hover:bg-red-500={$appSession.isAdmin}
		class="btn btn-lg btn-error text-sm"
	>
		Delete Service
	</button>
</div>
