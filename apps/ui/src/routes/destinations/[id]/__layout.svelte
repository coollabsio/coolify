<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(destination: any): string | null {
		let configurationPhase = null;
		if (!destination?.remoteEngine) return configurationPhase;
		if (!destination?.sshKey) {
			configurationPhase = 'sshkey';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ url, params }) => {
		try {
			const { id } = params;
			const response = await get(`/destinations/${id}`);
			const { destination, settings } = response;
			if (id !== 'new' && (!destination || Object.entries(destination).length === 0)) {
				return {
					status: 302,
					redirect: '/destinations'
				};
			}
			const configurationPhase = checkConfiguration(destination);
			if (
				configurationPhase &&
				url.pathname !== `/destinations/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/destinations/${params.id}/configuration/${configurationPhase}`
				};
			}

			return {
				props: {
					destination
				},
				stuff: {
					destination,
					settings
				}
			};
		} catch (error) {
			console.log(error)
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let destination: any;

	import { del, get } from '$lib/api';
	import { t } from '$lib/translations';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { appSession } from '$lib/store';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';

	const { id } = $page.params;
	const isDestinationDeletable = destination.application.length === 0 && destination.database.length === 0 && destination.service.length === 0
	
	async function deleteDestination(destination: any) {
		if (!isDestinationDeletable) return
		const sure = confirm($t('application.confirm_to_delete', { name: destination.name }));
		if (sure) {
			try {
				await del(`/destinations/${destination.id}`, { id: destination.id });
				return await goto('/destinations');
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	function deletable() {
		if (!isDestinationDeletable) {
			return "Please delete all resources before deleting this."
		}
		if ($appSession.isAdmin) {
			return $t('destination.delete_destination')
		} else {
			return $t('destination.permission_denied_delete_destination')
		}
	}
</script>

{#if id !== 'new'}
	<nav class="nav-side">
		<button
			on:click={() => deleteDestination(destination)}
			type="submit"
			disabled={!$appSession.isAdmin && isDestinationDeletable}
			class:hover:text-red-500={$appSession.isAdmin && isDestinationDeletable}
			class="icons tooltip tooltip-left bg-transparent text-sm"
			class:text-stone-600={!isDestinationDeletable}
			data-tip={deletable()}><DeleteIcon /></button
		>
	</nav>
{/if}
<slot />
