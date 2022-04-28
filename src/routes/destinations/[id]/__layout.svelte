<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		const url = `/destinations/${params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { destination, state, settings } = await res.json();
			if (!destination || Object.entries(destination).length === 0) {
				return {
					status: 302,
					redirect: '/destinations'
				};
			}
			return {
				props: {
					destination,
					state
				},
				stuff: {
					destination,
					settings,
					state
				}
			};
		}

		return {
			status: 302,
			redirect: '/destinations'
		};
	};
</script>

<script>
	import { session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { del } from '$lib/api';
	import { goto } from '$app/navigation';
	import { t } from '$lib/translations';

	export let destination;
	async function deleteDestination(destination) {
		const sure = confirm($t('application.confirm_to_delete', { name: destination.name }));
		if (sure) {
			try {
				await del(`/destinations/${destination.id}.json`, { id: destination.id });
				return await goto('/destinations');
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	}
</script>

<nav class="nav-side">
	<button
		on:click={() => deleteDestination(destination)}
		title={$t('destination.delete_destination')}
		type="submit"
		disabled={!$session.isAdmin}
		class:hover:text-red-500={$session.isAdmin}
		class="icons tooltip-bottom bg-transparent text-sm"
		data-tooltip={$session.isAdmin
			? $t('destination.delete_destination')
			: $t('destination.permission_denied_delete_destination')}><DeleteIcon /></button
	>
</nav>
<slot />
