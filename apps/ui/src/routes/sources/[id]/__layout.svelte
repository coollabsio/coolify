<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';

	export const load: Load = async ({ fetch, url, params }) => {
		try {
			const { id } = params;
			const response = await get(`/sources/${id}`);
			const { source, settings } = response;
			if (id !== 'new' && (!source || Object.entries(source).length === 0)) {
				return {
					status: 302,
					redirect: '/sources'
				};
			}
			return {
				props: {
					source
				},
				stuff: {
					source,
					settings
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let source: any;
	import { del, get } from '$lib/api';

	import { page } from '$app/stores';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import { t } from '$lib/translations';
	import { appSession } from '$lib/store';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { goto } from '$app/navigation';
	const { id } = $page.params;

	async function deleteSource(name: string) {
		const sure = confirm($t('application.confirm_to_delete', { name }));
		if (sure) {
			try {
				await del(`/sources/${id}`, {});
				await goto('/sources', { replaceState: true });
			} catch (error) {
				errorNotification(error);
			}
		}
	}
</script>

{#if id !== 'new'}
	<nav class="nav-side">
		<button
			on:click={() => deleteSource(source.name)}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:hover:text-red-500={$appSession.isAdmin}
			class="icons tooltip tooltip-bottom bg-transparent text-sm"
			data-tip={$appSession.isAdmin
				? $t('source.delete_git_source')
				: $t('source.permission_denied')}><DeleteIcon /></button
		>
	</nav>
{/if}
<slot />
