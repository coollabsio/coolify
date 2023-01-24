<script lang="ts">
	import type { LayoutData } from './$types';
	export let data: LayoutData;
	let destination = data.destination.destination;

	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/common';
	import { appSession, trpc } from '$lib/store';
	import * as Icons from '$lib/components/icons';
	import Tooltip from '$lib/components/Tooltip.svelte';

	const isDestinationDeletable =
		(destination?.application.length === 0 &&
			destination?.database.length === 0 &&
			destination?.service.length === 0) ||
		true;

	async function deleteDestination(destination: any) {
		if (!isDestinationDeletable) return;
		const sure = confirm("Are you sure you want to delete this destination? This can't be undone.");
		if (sure) {
			try {
				await trpc.destinations.delete.mutate({ id: destination.id });
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
			return "Delete this destination. This can't be undone.";
		} else {
			return "You don't have permission to delete this destination.";
		}
	}
</script>

{#if $page.params.id !== 'new'}
	<nav class="header lg:flex-row flex-col-reverse">
		<div class="flex flex-row space-x-2 font-bold pt-10 lg:pt-0">
			<div class="flex flex-col items-center justify-center title">
				{#if $page.url.pathname === `/destinations/${$page.params.id}`}
					Configurations
				{:else if $page.url.pathname.startsWith(`/destinations/${$page.params.id}/configuration/sshkey`)}
					Select a SSH Key
				{/if}
			</div>
		</div>
		<div class="lg:block hidden flex-1" />
		<div class="flex flex-row flex-wrap space-x-3 justify-center lg:justify-start lg:py-0">
			<button
				id="delete"
				on:click={() => deleteDestination(destination)}
				type="submit"
				disabled={!$appSession.isAdmin && isDestinationDeletable}
				class:hover:text-red-500={$appSession.isAdmin && isDestinationDeletable}
				class="icons bg-transparent text-sm"
				class:text-stone-600={!isDestinationDeletable}><Icons.Delete /></button
			>
			<Tooltip triggeredBy="#delete">{deletable()}</Tooltip>
		</div>
	</nav>
{/if}
<slot />
