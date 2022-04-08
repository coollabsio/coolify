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
			const { database, isRunning, versions, privatePort, settings } = await res.json();
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
					isRunning,
					versions,
					privatePort
				},
				stuff: {
					database,
					isRunning,
					versions,
					privatePort,
					settings
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
	import { del, post } from '$lib/api';
	import { goto } from '$app/navigation';

	export let database;
	export let isRunning;
	let loading = false;

	async function deleteDatabase() {
		const sure = confirm(`Are you sure you would like to delete '${database.name}'?`);
		if (sure) {
			loading = true;
			try {
				await del(`/databases/${database.id}/delete.json`, { id: database.id });
				return await goto('/databases');
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				loading = false;
			}
		}
	}
	async function stopDatabase() {
		const sure = confirm(`Are you sure you would like to stop '${database.name}'?`);
		if (sure) {
			loading = true;
			try {
				await post(`/databases/${database.id}/stop.json`, {});
				return window.location.reload();
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	}
	async function startDatabase() {
		loading = true;
		try {
			await post(`/databases/${database.id}/start.json`, {});
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<nav class="nav-side">
	{#if loading}
		<Loading fullscreen cover />
	{:else}
		{#if database.type && database.destinationDockerId && database.version && database.defaultDatabase}
			{#if isRunning}
				<button
					on:click={stopDatabase}
					title="Stop database"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
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
			{:else}
				<button
					on:click={startDatabase}
					title="Start database"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
					data-tooltip={$session.isAdmin
						? 'Start database'
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
