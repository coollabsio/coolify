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
			const { application, githubToken, gitlabToken, ghToken } = await res.json();
			if (!application || Object.entries(application).length === 0) {
				return {
					status: 302,
					redirect: '/applications'
				};
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
					application
				},
				stuff: {
					ghToken,
					githubToken,
					gitlabToken,
					application
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
	import { page, session } from '$app/stores';
	import { enhance, errorNotification } from '$lib/form';
	import { appConfiguration } from '$lib/store';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import Loading from '$lib/components/Loading.svelte';

	export let application;
	let loading = false;

	const { id } = $page.params;
	$appConfiguration.configuration = application;

	async function deleteApplication(name) {
		const sure = confirm(`Are you sure you would like to delete '${name}'?`);
		if (sure) {
			loading = true;
			const response = await fetch(`/applications/${id}/delete.json`, {
				method: 'delete',
				body: JSON.stringify({ id })
			});
			if (!response.ok) {
				const { message } = await response.json();
				loading = false;
				errorNotification(message);
			} else {
				window.location.assign('/applications');
			}
		}
	}
</script>

<nav class="nav-side">
	{#if loading}
		<Loading fullscreen cover />
	{:else}
		{#if application.domain && $appConfiguration.configuration.gitSource && $appConfiguration.configuration.repository && $appConfiguration.configuration.destinationDocker && $appConfiguration.configuration.buildPack}
			<!-- svelte-ignore missing-declaration -->
			<form
				action="/applications/{id}/deploy.json"
				method="post"
				use:enhance={{
					beforeSubmit: async () => {
						const form = new FormData();
						form.append('domain', $appConfiguration.configuration.domain);
						form.append('port', $appConfiguration?.configuration?.port?.toString() || '');
						form.append('installCommand', $appConfiguration.configuration.installCommand || '');
						form.append('buildCommand', $appConfiguration.configuration.buildCommand || '');
						form.append('startCommand', $appConfiguration.configuration.startCommand || '');
						form.append('baseDirectory', $appConfiguration.configuration.baseDirectory || '');
						form.append('publishDirectory', $appConfiguration.configuration.publishDirectory || '');
						const response = await fetch(`/applications/${id}.json`, {
							method: 'POST',
							headers: {
								accept: 'application/json'
							},
							body: form
						});
						if (!response.ok) {
							errorNotification(
								`Application configuration '${$appConfiguration.configuration.name}' failed to update!`
							);
							throw new Error(await response.json());
						}
					},
					result: async (res) => {
						const { buildId } = await res.json();
						window.location.assign(`/applications/${id}/logs?buildId=${buildId}`);
					}
				}}
			>
				<button
					title="Queue for deployment"
					type="submit"
					disabled={!$session.isAdmin}
					class:text-green-500={$session.isAdmin &&
						$appConfiguration.configuration.gitSource &&
						$appConfiguration.configuration.repository &&
						$appConfiguration.configuration.destinationDocker}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 hover:bg-green-600 hover:text-white"
					data-tooltip={$session.isAdmin
						? 'Queue for deployment'
						: 'You do not have permission to deploy.'}
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
						<path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-1" />
						<polyline points="9 15 12 12 15 15" />
						<line x1="12" y1="12" x2="12" y2="21" />
					</svg>
				</button>
			</form>

			<div class="border border-warmGray-700 h-8" />
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
					title="Secrets"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
					data-tooltip="Secrets"
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
			<div class="border border-warmGray-700 h-8" />
			<a
				href="/applications/{id}/logs"
				sveltekit:prefetch
				class="hover:text-sky-500 rounded"
				class:text-sky-500={$page.url.pathname === `/applications/${id}/logs`}
				class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs`}
			>
				<button
					title="Logs"
					class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500 "
					data-tooltip="Logs"
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
			<div class="border border-warmGray-700 h-8" />
		{/if}

		<button
			on:click={() => deleteApplication($appConfiguration.configuration.name)}
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
