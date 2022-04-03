<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(application): string {
		let configurationPhase = null;
		if (!application.gitSourceId) {
			configurationPhase = 'source';
		} else if (!application.repository && !application.branch) {
			configurationPhase = 'repository';
		} else if (!application.destinationDockerId) {
			configurationPhase = 'destination';
		} else if (!application.buildPack) {
			configurationPhase = 'buildpack';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, url, params }) => {
		const endpoint = `/applications/${params.id}.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			let { application, isRunning, appId, githubToken, gitlabToken } = await res.json();
			if (!application || Object.entries(application).length === 0) {
				return {
					status: 302,
					redirect: '/applications'
				};
			}
			if (application.gitSource?.githubAppId && !githubToken) {
				const response = await fetch(`/applications/${params.id}/configuration/githubToken.json`);
				if (response.ok) {
					const { token } = await response.json();
					githubToken = token;
				}
			}
			const configurationPhase = checkConfiguration(application);
			if (
				configurationPhase &&
				url.pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/applications/${params.id}/configuration/${configurationPhase}`
				};
			}

			return {
				props: {
					application,
					isRunning,
					githubToken,
					gitlabToken
				},
				stuff: {
					isRunning,
					application,
					appId
				}
			};
		}

		return {
			status: 302,
			redirect: '/applications'
		};
	};
</script>

<script lang="ts">
	export let application;
	export let isRunning;
	export let githubToken;
	export let gitlabToken;
	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import Loading from '$lib/components/Loading.svelte';
	import { del, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { gitTokens } from '$lib/store';
	import { toast } from '@zerodevx/svelte-toast';

	if (githubToken) $gitTokens.githubToken = githubToken;
	if (gitlabToken) $gitTokens.gitlabToken = gitlabToken;

	let loading = false;
	const { id } = $page.params;

	async function handleDeploySubmit() {
		try {
			const { buildId } = await post(`/applications/${id}/deploy.json`, { ...application });
			toast.push('Deployment queued.');
			if ($page.url.pathname.startsWith(`/applications/${id}/logs/build`)) {
				return window.location.assign(`/applications/${id}/logs/build?buildId=${buildId}`);
			} else {
				return await goto(`/applications/${id}/logs/build?buildId=${buildId}`, {
					replaceState: true
				});
			}
		} catch ({ error }) {
			return errorNotification(error);
		}
	}

	async function deleteApplication(name) {
		const sure = confirm(`Are you sure you would like to delete '${name}'?`);
		if (sure) {
			loading = true;
			try {
				await del(`/applications/${id}/delete.json`, { id });
				return await goto(`/applications`);
			} catch ({ error }) {
				return errorNotification(error);
			}
		}
	}
	async function stopApplication() {
		try {
			loading = true;
			await post(`/applications/${id}/stop.json`, {});
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
		{#if application.fqdn && application.gitSource && application.repository && application.destinationDocker && application.buildPack}
			{#if isRunning}
				<button
					on:click={stopApplication}
					title="Stop application"
					type="submit"
					disabled={!$session.isAdmin}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
					data-tooltip={$session.isAdmin
						? 'Stop application'
						: 'You do not have permission to stop the application.'}
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
				<form on:submit|preventDefault={handleDeploySubmit}>
					<button
						title="Rebuild application"
						type="submit"
						disabled={!$session.isAdmin}
						class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 hover:text-green-500"
						data-tooltip={$session.isAdmin
							? 'Rebuild application'
							: 'You do not have permission to rebuild application.'}
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
							<path
								d="M16.3 5h.7a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h5l-2.82 -2.82m0 5.64l2.82 -2.82"
								transform="rotate(-45 12 12)"
							/>
						</svg>
					</button>
				</form>
			{:else}
				<form on:submit|preventDefault={handleDeploySubmit}>
					<button
						title="Build and start application"
						type="submit"
						disabled={!$session.isAdmin}
						class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
						data-tooltip={$session.isAdmin
							? 'Build and start application'
							: 'You do not have permission to Build and start application.'}
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
							<path d="M7 4v16l13 -8z" />
						</svg>
					</button>
				</form>
			{/if}

			<div class="border border-stone-700 h-8" />
			<a
				href="/applications/{id}"
				sveltekit:prefetch
				class="hover:text-yellow-500 rounded"
				class:text-yellow-500={$page.url.pathname === `/applications/${id}`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}`}
			>
				<button
					title="Configurations"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Configurations"
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
			<a
				href="/applications/{id}/secrets"
				sveltekit:prefetch
				class="hover:text-pink-500 rounded"
				class:text-pink-500={$page.url.pathname === `/applications/${id}/secrets`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/secrets`}
			>
				<button
					title="Secret"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Secret"
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
						<path
							d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
						/>
						<circle cx="12" cy="11" r="1" />
						<line x1="12" y1="12" x2="12" y2="14.5" />
					</svg></button
				></a
			>
			<a
				href="/applications/{id}/storage"
				sveltekit:prefetch
				class="hover:text-pink-500 rounded"
				class:text-pink-500={$page.url.pathname === `/applications/${id}/storage`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/storage`}
			>
				<button
					title="Persistent Storage"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Persistent Storage"
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
						<ellipse cx="12" cy="6" rx="8" ry="3" />
						<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
						<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
					</svg>
				</button></a
			>
			<a
				href="/applications/{id}/previews"
				sveltekit:prefetch
				class="hover:text-orange-500 rounded"
				class:text-orange-500={$page.url.pathname === `/applications/${id}/previews`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/previews`}
			>
				<button
					title="Previews"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Previews"
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
						<circle cx="7" cy="18" r="2" />
						<circle cx="7" cy="6" r="2" />
						<circle cx="17" cy="12" r="2" />
						<line x1="7" y1="8" x2="7" y2="16" />
						<path d="M7 8a4 4 0 0 0 4 4h4" />
					</svg></button
				></a
			>
			<div class="border border-stone-700 h-8" />
			<a
				href="/applications/{id}/logs"
				sveltekit:prefetch
				class="hover:text-sky-500 rounded"
				class:text-sky-500={$page.url.pathname === `/applications/${id}/logs`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs`}
			>
				<button
					title="Application Logs"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500 "
					data-tooltip="Application Logs"
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
					</svg>
				</button></a
			>
			<a
				href="/applications/{id}/logs/build"
				sveltekit:prefetch
				class="hover:text-red-500 rounded"
				class:text-red-500={$page.url.pathname === `/applications/${id}/logs/build`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs/build`}
			>
				<button
					title="Build Logs"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500 "
					data-tooltip="Build Logs"
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
						<circle cx="19" cy="13" r="2" />
						<circle cx="4" cy="17" r="2" />
						<circle cx="13" cy="17" r="2" />
						<line x1="13" y1="19" x2="4" y2="19" />
						<line x1="4" y1="15" x2="13" y2="15" />
						<path d="M8 12v-5h2a3 3 0 0 1 3 3v5" />
						<path d="M5 15v-2a1 1 0 0 1 1 -1h7" />
						<path d="M19 11v-7l-6 7" />
					</svg>
				</button></a
			>
			<div class="border border-stone-700 h-8" />
		{/if}

		<button
			on:click={() => deleteApplication(application.name)}
			title="Delete application"
			type="submit"
			disabled={!$session.isAdmin}
			class:hover:text-red-500={$session.isAdmin}
			class="icons bg-transparent  tooltip-bottom text-sm"
			data-tooltip={$session.isAdmin
				? 'Delete application'
				: 'You do not have permission to delete this application'}
		>
			<DeleteIcon />
		</button>
	{/if}
</nav>
<slot />
