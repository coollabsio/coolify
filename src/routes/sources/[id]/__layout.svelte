<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		const url = `/sources/${params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { source, settings } = await res.json();
			if (!source || Object.entries(source).length === 0) {
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
		}

		return {
			status: 302,
			redirect: '/sources'
		};
	};
</script>

<script>
	export let source;
	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { t } from '$lib/translations';
	const { id } = $page.params;

	async function deleteSource(name) {
		const sure = confirm($t('application.confirm_to_delete', { name: name }));
		if (sure) {
			const response = await fetch(`/sources/${id}.json`, {
				method: 'delete'
			});
			if (!response.ok) {
				const { message } = await response.json();
				errorNotification(message);
			} else {
				window.location.assign('/sources');
			}
		}
	}
</script>

<nav class="nav-side">
	<button
		on:click={() => deleteSource(source.name)}
		title={$t('source.delete_git_source')}
		type="submit"
		disabled={!$session.isAdmin}
		class:hover:text-red-500={$session.isAdmin}
		class="icons tooltip-bottom bg-transparent text-sm"
		data-tooltip={$session.isAdmin
			? $t('source.delete_git_source')
			: $t('source.permission_denied')}><DeleteIcon /></button
	>
</nav>
<slot />
