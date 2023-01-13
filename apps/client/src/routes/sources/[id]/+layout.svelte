<script lang="ts">
	import type { LayoutData } from './$types';

	export let data: LayoutData;
	let source = data.source.data.source;
	import { page } from '$app/stores';
	import { errorNotification } from '$lib/common';
	import { appSession, trpc } from '$lib/store';
	import * as Icons from '$lib/components/icons';
	import { goto } from '$app/navigation';
	import Tooltip from '$lib/components/Tooltip.svelte';
	const { id } = $page.params;

	async function deleteSource(name: string) {
		const sure = confirm('Are you sure you want to delete ' + name + '?');
		if (sure) {
			try {
				await trpc.sources.delete.mutate({ id });
				await goto('/', { replaceState: true });
			} catch (error) {
				errorNotification(error);
			}
		}
	}
</script>

{#if id !== 'new' && $appSession.teamId === '0'}
	<nav class="nav-side">
		<button
			id="delete"
			on:click={() => deleteSource(source.name)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:hover:text-red-500={$appSession.isAdmin}
			class="icons bg-transparent text-sm"><Icons.Delete /></button
		>
	</nav>
	<Tooltip triggeredBy="#delete">Delete</Tooltip>
{/if}
<slot />
