<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	let service: any = data.service.data;
	import { page } from '$app/stores';
	import { appSession, status, trpc } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import { goto } from '$app/navigation';
	const { id } = $page.params;
	
	async function deleteService() {
		const sure = confirm('Are you sure you want to delete this service?');
		if (sure) {
			$status.service.initialLoading = true;
			try {
				if (service.type && $status.service.overallStatus !== 'stopped') {
					await trpc.services.stop.mutate({ id });
				}
				await trpc.services.delete.mutate({ id });
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
