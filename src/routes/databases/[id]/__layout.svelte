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
	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import Loading from '$lib/components/Loading.svelte';
	import { del, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { t } from '$lib/translations';

	const { id } = $page.params;

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
		const sure = confirm($t('database.confirm_stop', { name: database.name }));
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
					title={$t('database.stop_database')}
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
					data-tooltip={$session.isAdmin
						? $t('database.stop_database')
						: $t('database.permission_denied_stop_database')}
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
					title={$t('database.start_database')}
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
					data-tooltip={$session.isAdmin
						? $t('database.start_database')
						: $t('database.permission_denied_start_database')}
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
		<div class="border border-stone-700 h-8" />
		<a
			href="/databases/{id}"
			sveltekit:prefetch
			class="hover:text-yellow-500 rounded"
			class:text-yellow-500={$page.url.pathname === `/databases/${id}`}
			class:bg-coolgray-500={$page.url.pathname === `/databases/${id}`}
		>
			<button
				title={$t('application.configurations')}
				class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
				data-tooltip={$t('application.configurations')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<rect x="4" y="8" width="4" height="4" />
					<line x1="6" y1="4" x2="6" y2="8" />
					<line x1="6" y1="12" x2="6" y2="20" />
					<rect x="10" y="14" width="4" height="4" />
					<line x1="12" y1="4" x2="12" y2="14" />
					<line x1="12" y1="18" x2="12" y2="20" />
					<rect x="16" y="5" width="4" height="4" />
					<line x1="18" y1="4" x2="18" y2="5" />
					<line x1="18" y1="9" x2="18" y2="20" />
				</svg></button
			></a
		>
		<div class="border border-stone-700 h-8" />
		<a
			href={isRunning ? `/databases/${id}/logs` : null}
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/databases/${id}/logs`}
			class:bg-coolgray-500={$page.url.pathname === `/databases/${id}/logs`}
		>
			<button
				title={$t('database.logs')}
				disabled={!isRunning}
				class="icons bg-transparent tooltip-bottom text-sm"
				data-tooltip={$t('database.logs')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
					<path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
					<line x1="3" y1="6" x2="3" y2="19" />
					<line x1="12" y1="6" x2="12" y2="19" />
					<line x1="21" y1="6" x2="21" y2="19" />
				</svg></button
			></a
		>
		<button
			on:click={deleteDatabase}
			title={$t('database.delete_database')}
			type="submit"
			disabled={!$session.isAdmin}
			class:hover:text-red-500={$session.isAdmin}
			class="icons bg-transparent tooltip-bottom text-sm"
			data-tooltip={$session.isAdmin
				? $t('database.delete_database')
				: $t('database.permission_denied_delete_database')}><DeleteIcon /></button
		>
	{/if}
</nav>
<slot />
