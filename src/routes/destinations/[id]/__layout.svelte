<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params }) => {
		console.log(params)
		const url = `/destinations/${params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { destination } = await res.json();
			if (!destination || Object.entries(destination).length === 0) {
				return {
					status: 302,
					redirect: '/destinations'
				};
			}
			return {
				props: {
					destination
				},
				stuff: {
					destination
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

	export let destination;
	async function deleteDestination(destination) {
		const sure = confirm(`Are you sure you would like to delete '${destination.name}'?`);
		if (sure) {
			const response = await fetch(`/destinations/${destination.id}.json`, {
				method: 'delete',
				body: JSON.stringify({ id: destination.id })
			});
			if (!response.ok) {
				const { message } = await response.json();
				errorNotification(message);
			} else {
				window.location.assign('/destinations');
			}
		}
	}
</script>

<nav class="nav-side">
	<button
		on:click={() => deleteDestination(destination)}
		title="Delete Destination"
		type="submit"
		disabled={!$session.isAdmin}
		class:hover:text-red-500={$session.isAdmin}
		class="icons bg-transparent tooltip-bottom text-sm"
		data-tooltip={$session.isAdmin
			? 'Delete Git Source'
			: 'You do not have permission to delete a Git Source'}><DeleteIcon /></button
	>
</nav>
<slot />
