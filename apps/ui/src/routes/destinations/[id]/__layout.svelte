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
					redirect: '/'
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
	import Tooltip from '$lib/components/Tooltip.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import DeleteButton from '$lib/components/buttons/DeleteButton.svelte';

	const isDestinationDeletable =
		(destination?.application.length === 0 &&
			destination?.database.length === 0 &&
			destination?.service.length === 0) ||
		true;

	async function deleteDestination(destination: any) {
		if (!isDestinationDeletable) return;
		const sure = confirm($t('application.confirm_to_delete', { name: destination.name }));
		if (sure) {
			try {
				await del(`/destinations/${destination.id}`, { id: destination.id });
				return await goto('/', { replaceState: true });
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	function deletable() {
		if (!isDestinationDeletable) {
			return 'Please delete all resources before deleting this.';
		}
		if ($appSession.isAdmin) {
			return $t('destination.delete_destination');
		} else {
			return $t('destination.permission_denied_delete_destination');
		}
	}
</script>

{#if $page.params.id !== 'new'}
	<ContextMenu>
		<div class="title">
			{#if $page.url.pathname === `/destinations/${$page.params.id}`}
				Configurations
			{:else if $page.url.pathname.startsWith(`/destinations/${$page.params.id}/configuration/sshkey`)}
				Select a SSH Key
			{/if}
		</div>
		<div class="actions">
			<DeleteButton action={() => deleteDestination(destination)} disabled={!$appSession.isAdmin && isDestinationDeletable}/>
		</div>
	</ContextMenu>
{/if}

<slot />
