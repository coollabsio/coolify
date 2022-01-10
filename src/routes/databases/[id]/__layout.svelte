<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(database): string {
		let configurationPhase = null;
		if (!database.type) {
			configurationPhase = 'type';
		} else if (!database.version) {
			configurationPhase = 'version';
		} else if (!database.destinationDockerId) {
			configurationPhase = 'destination';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, params, url }) => {
		const endpoint = `/databases/${params.id}.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			const { database, state, versions } = await res.json();
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
					database,
					state,
					versions
				},
				stuff: {
					database,
					state,
					versions
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
	import Loading from '$lib/components/Loading.svelte';

	export let database;
	export let state;
	let loading = false;

	async function deleteDatabase() {
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
	async function stopDatabase() {
		const sure = confirm(`Are you sure you would like to stop '${database.name}'?`);
		if (sure) {
			loading = true;
			const response = await fetch(`/databases/${database.id}/stop.json`, {
				method: 'POST'
			});
			if (!response.ok) {
				loading = false;
				const { message } = await response.json();
				errorNotification(message);
			} else {
				window.location.reload();
			}
		}
	}
	async function startDatabase() {
		loading = true;
		const response = await fetch(`/databases/${database.id}/start.json`, {
			method: 'POST'
		});
		if (!response.ok) {
			loading = false;
			const { message } = await response.json();
			errorNotification(message);
		} else {
			window.location.reload();
		}
	}
</script>

<nav class="nav-side">
	{#if loading}
		<Loading fullscreen cover />
	{:else}
		{#if database.type && database.destinationDockerId && database.version}
			{#if state === 'running'}
				<button
					on:click={stopDatabase}
					title="Stop Database"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 hover:bg-green-600 hover:text-white"
					data-tooltip={$session.isAdmin
						? 'Stop database'
						: 'You do not have permission to stop the database.'}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-6 h-6"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<rect x="6" y="5" width="4" height="14" rx="1" />
						<rect x="14" y="5" width="4" height="14" rx="1" />
					</svg>
				</button>
			{:else if state === 'not started'}
				<button
					on:click={startDatabase}
					title="Start Database"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 hover:bg-green-600 hover:text-white"
					data-tooltip={$session.isAdmin
						? 'Start Database'
						: 'You do not have permission to start the database.'}
					><svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-6 h-6"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path d="M7 4v16l13 -8z" />
					</svg>
				</button>
			{/if}
		{/if}
		<button
			on:click={deleteDatabase}
			title="Delete Database"
			type="submit"
			disabled={!$session.isAdmin}
			class:hover:text-red-500={$session.isAdmin}
			class="icons bg-transparent tooltip-bottom text-sm"
			data-tooltip={$session.isAdmin
				? 'Delete Database'
				: 'You do not have permission to delete a Database'}><DeleteIcon /></button
		>
	{/if}
</nav>
<slot />
