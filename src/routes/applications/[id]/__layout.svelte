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
	export const load: Load = async ({ fetch, page }) => {
		const url = `/applications/${page.params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { application, githubToken, gitlabToken } = await res.json();
			if (!application || Object.entries(application).length === 0) {
				return {
					status: 302,
					redirect: '/applications'
				};
			}
			const configurationPhase = checkConfiguration(application);
			if (
				configurationPhase &&
				page.path !== `/applications/${page.params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/applications/${page.params.id}/configuration/${configurationPhase}`
				};
			}

			return {
				props: {
					application
				},
				stuff: {
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

	export let application;
	const { id } = $page.params;
	$appConfiguration.configuration = application;

	async function deleteApplication(name) {
		const sure = confirm(`Are you sure you would like to delete '${name}'?`);
		if (sure) {
			const response = await fetch(`/applications/${id}/delete.json`, {
				method: 'delete',
				body: JSON.stringify({ id })
			});
			if (!response.ok) {
				const { message } = await response.json();
				errorNotification(message);
			} else {
				window.location.assign('/applications');
			}
		}
	}
</script>

<nav class="nav-side">
	{#if application.domain}
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
				title="Deploy"
				type="submit"
				disabled={!$session.isAdmin}
				class:text-green-500={$session.isAdmin &&
					$appConfiguration.configuration.gitSource &&
					$appConfiguration.configuration.repository &&
					$appConfiguration.configuration.destinationDocker}
				class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2"
				data-tooltip={$session.isAdmin
					? 'Queue for deployment'
					: 'You do not have permission to deploy.'}
			>
				<div>Build & Deploy</div>
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

		<div class="border border-warmGray-700 h-8" />
		<a
			href="/applications/{id}"
			sveltekit:prefetch
			class="hover:text-yellow-500 rounded"
			class:text-yellow-500={$page.path === `/applications/${id}`}
			class:bg-coolgray-500={$page.path === `/applications/${id}`}
		>
			<button
				title="Configuration"
				class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
				data-tooltip="Configuration"
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
			href="/applications/{id}/logs"
			sveltekit:prefetch
			class="hover:text-sky-500 rounded"
			class:text-sky-500={$page.path === `/applications/${id}/logs`}
			class:bg-coolgray-500={$page.path === `/applications/${id}/logs`}
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
</nav>
<slot />
