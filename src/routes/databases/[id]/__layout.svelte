<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(database): string {
		let configurationPhase = null;
		if (!database.type) {
			configurationPhase = 'type';
		} 
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, params, url }) => {
		const endpoint = `/databases/${params.id}.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			const { database } = await res.json();
			if (!database || Object.entries(database).length === 0) {
				return {
					status: 302,
					redirect: '/databases'
				};
			}
			const configurationPhase = checkConfiguration(database);
			if (
				configurationPhase &&
				url.pathname !== `/databases/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/databases/${params.id}/configuration/${configurationPhase}`
				};
			}
			return {
				props: {
					database
				},
				stuff: {
					database
				}
			};
		}

		return {
			status: 302,
			redirect: '/databases'
		};
	};
</script>

<script>
	import { session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';

	export let database;
	async function deleteDatabase(database) {
		const sure = confirm(`Are you sure you would like to delete '${database.name}'?`);
		if (sure) {
			const response = await fetch(`/databases/${database.id}/delete.json`, {
				method: 'delete',
				body: JSON.stringify({ id: database.id })
			});
			if (!response.ok) {
				const { message } = await response.json();
				errorNotification(message);
			} else {
				window.location.assign('/databases');
			}
		}
	}
</script>

<nav class="nav-side">
	<button
		on:click={() => deleteDatabase(database)}
		title="Delete Database"
		type="submit"
		disabled={!$session.isAdmin}
		class:hover:text-red-500={$session.isAdmin}
		class="icons bg-transparent tooltip-bottom text-sm"
		data-tooltip={$session.isAdmin
			? 'Delete Database'
			: 'You do not have permission to delete a Database'}><DeleteIcon /></button
	>
</nav>
<slot />
