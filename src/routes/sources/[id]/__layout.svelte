<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page }) => {
		const url = `/sources/${page.params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { source } = await res.json();
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
					source
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
	const { id } = $page.params;

	async function deleteSource(name) {
		const sure = confirm(`Are you sure you would like to delete '${name}'?`);
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
		title="Delete Git Source"
		type="submit"
		disabled={$session.permission !== 'admin'}
		class:hover:text-red-500={$session.permission === 'admin'}
		class="icons bg-transparent tooltip-bottom text-sm"
		data-tooltip={$session.permission === 'admin'
			? 'Delete Git Source'
			: 'You do not have permission to delete a Git Source'}
		><svg
			class="w-6 h-6"
			fill="none"
			stroke="currentColor"
			viewBox="0 0 24 24"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
			/>
		</svg></button
	>
</nav>
<slot />
