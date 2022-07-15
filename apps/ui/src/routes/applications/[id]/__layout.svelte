<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(application: any): string | null {
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
		try {
			const response = await get(`/applications/${params.id}`);
			let { application, appId, settings, isQueueActive } = response;
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
					isQueueActive,
					application
				},
				stuff: {
					application,
					appId,
					settings
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	export let application: any;
	export let isQueueActive: any;

	import { page } from '$app/stores';
	import DeleteIcon from '$lib/components/DeleteIcon.svelte';
	import { del, get, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { toast } from '@zerodevx/svelte-toast';
	import { onDestroy, onMount } from 'svelte';
	import { t } from '$lib/translations';
	import { appSession, disabledButton, status } from '$lib/store';
	import { errorNotification, handlerNotFoundLoad } from '$lib/common';
	import Loading from '$lib/components/Loading.svelte';

	let loading = false;
	let statusInterval: any;
	$disabledButton =
		!$appSession.isAdmin ||
		!application.fqdn ||
		!application.gitSource ||
		!application.repository ||
		!application.destinationDocker ||
		!application.buildPack;
	let location = application.fqdn || null;
	if (GITPOD_WORKSPACE_URL && application.exposePort) {
		const { href } = new URL(GITPOD_WORKSPACE_URL);
		const newURL = href
			.replace('https://', `https://${application.exposePort}-`)
			.replace(/\/$/, '');
		location = newURL;
	}
	const { id } = $page.params;

	async function handleDeploySubmit() {
		try {
			const { buildId } = await post(`/applications/${id}/deploy`, { ...application });
			toast.push($t('application.deployment_queued'));
			if ($page.url.pathname.startsWith(`/applications/${id}/logs/build`)) {
				return window.location.assign(`/applications/${id}/logs/build?buildId=${buildId}`);
			} else {
				return await goto(`/applications/${id}/logs/build?buildId=${buildId}`, {
					replaceState: true
				});
			}
		} catch (error) {
			return errorNotification(error);
		}
	}

	async function deleteApplication(name: string) {
		const sure = confirm($t('application.confirm_to_delete', { name }));
		if (sure) {
			loading = true;
			try {
				await del(`/applications/${id}`, { id });
				return await goto(`/applications`);
			} catch (error) {
				return errorNotification(error);
			}
		}
	}
	async function stopApplication() {
		try {
			loading = true;
			await post(`/applications/${id}/stop`, {});
			return window.location.reload();
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function getStatus() {
		if ($status.application.loading) return;
		$status.application.loading = true;
		const data = await get(`/applications/${id}`);
		isQueueActive = data.isQueueActive;
		$status.application.isRunning = data.isRunning;
		$status.application.isExited = data.isExited;
		$status.application.loading = false;
		$status.application.initialLoading = false;
	}
	onDestroy(() => {
		$status.application.initialLoading = true;
		clearInterval(statusInterval);
	});
	onMount(async () => {
		$status.application.isRunning = false;
		$status.application.isExited = false;
		$status.application.loading = false;
		if (application.gitSourceId && application.destinationDockerId && application.fqdn) {
			await getStatus();
			statusInterval = setInterval(async () => {
				await getStatus();
			}, 2000);
		} else {
			$status.application.initialLoading = false;
		}
	});
</script>

<nav class="nav-side">
	{#if loading}
		<Loading fullscreen cover />
	{:else}
		{#if location}
			<a
				href={location}
				target="_blank"
				class="icons tooltip-bottom flex items-center bg-transparent text-sm"
				><svg
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
					<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
					<line x1="10" y1="14" x2="20" y2="4" />
					<polyline points="15 4 20 4 20 9" />
				</svg></a
			>
		{/if}
		{#if application.gitSource?.htmlUrl && application.repository && application.branch}
			<a
				href="{application.gitSource.htmlUrl}/{application.repository}/tree/{application.branch}"
				target="_blank"
				class="w-10"
			>
				{#if application.gitSource?.type === 'gitlab'}
					<svg viewBox="0 0 128 128" class="icons">
						<path
							fill="#FC6D26"
							d="M126.615 72.31l-7.034-21.647L105.64 7.76c-.716-2.206-3.84-2.206-4.556 0l-13.94 42.903H40.856L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664 1.385 72.31a4.792 4.792 0 001.74 5.358L64 121.894l60.874-44.227a4.793 4.793 0 001.74-5.357"
						/><path fill="#E24329" d="M64 121.894l23.144-71.23H40.856L64 121.893z" /><path
							fill="#FC6D26"
							d="M64 121.894l-23.144-71.23H8.42L64 121.893z"
						/><path
							fill="#FCA326"
							d="M8.42 50.663L1.384 72.31a4.79 4.79 0 001.74 5.357L64 121.894 8.42 50.664z"
						/><path
							fill="#E24329"
							d="M8.42 50.663h32.436L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664z"
						/><path fill="#FC6D26" d="M64 121.894l23.144-71.23h32.437L64 121.893z" /><path
							fill="#FCA326"
							d="M119.58 50.663l7.035 21.647a4.79 4.79 0 01-1.74 5.357L64 121.894l55.58-71.23z"
						/><path
							fill="#E24329"
							d="M119.58 50.663H87.145l13.94-42.902c.717-2.206 3.84-2.206 4.557 0l13.94 42.903z"
						/>
					</svg>
				{:else if application.gitSource?.type === 'github'}
					<svg viewBox="0 0 128 128" class="icons">
						<g fill="#ffffff"
							><path
								fill-rule="evenodd"
								clip-rule="evenodd"
								d="M64 5.103c-33.347 0-60.388 27.035-60.388 60.388 0 26.682 17.303 49.317 41.297 57.303 3.017.56 4.125-1.31 4.125-2.905 0-1.44-.056-6.197-.082-11.243-16.8 3.653-20.345-7.125-20.345-7.125-2.747-6.98-6.705-8.836-6.705-8.836-5.48-3.748.413-3.67.413-3.67 6.063.425 9.257 6.223 9.257 6.223 5.386 9.23 14.127 6.562 17.573 5.02.542-3.903 2.107-6.568 3.834-8.076-13.413-1.525-27.514-6.704-27.514-29.843 0-6.593 2.36-11.98 6.223-16.21-.628-1.52-2.695-7.662.584-15.98 0 0 5.07-1.623 16.61 6.19C53.7 35 58.867 34.327 64 34.304c5.13.023 10.3.694 15.127 2.033 11.526-7.813 16.59-6.19 16.59-6.19 3.287 8.317 1.22 14.46.593 15.98 3.872 4.23 6.215 9.617 6.215 16.21 0 23.194-14.127 28.3-27.574 29.796 2.167 1.874 4.097 5.55 4.097 11.183 0 8.08-.07 14.583-.07 16.572 0 1.607 1.088 3.49 4.148 2.897 23.98-7.994 41.263-30.622 41.263-57.294C124.388 32.14 97.35 5.104 64 5.104z"
							/><path
								d="M26.484 91.806c-.133.3-.605.39-1.035.185-.44-.196-.685-.605-.543-.906.13-.31.603-.395 1.04-.188.44.197.69.61.537.91zm2.446 2.729c-.287.267-.85.143-1.232-.28-.396-.42-.47-.983-.177-1.254.298-.266.844-.14 1.24.28.394.426.472.984.17 1.255zM31.312 98.012c-.37.258-.976.017-1.35-.52-.37-.538-.37-1.183.01-1.44.373-.258.97-.025 1.35.507.368.545.368 1.19-.01 1.452zm3.261 3.361c-.33.365-1.036.267-1.552-.23-.527-.487-.674-1.18-.343-1.544.336-.366 1.045-.264 1.564.23.527.486.686 1.18.333 1.543zm4.5 1.951c-.147.473-.825.688-1.51.486-.683-.207-1.13-.76-.99-1.238.14-.477.823-.7 1.512-.485.683.206 1.13.756.988 1.237zm4.943.361c.017.498-.563.91-1.28.92-.723.017-1.308-.387-1.315-.877 0-.503.568-.91 1.29-.924.717-.013 1.306.387 1.306.88zm4.598-.782c.086.485-.413.984-1.126 1.117-.7.13-1.35-.172-1.44-.653-.086-.498.422-.997 1.122-1.126.714-.123 1.354.17 1.444.663zm0 0"
							/></g
						>
					</svg>
				{/if}
			</a>
		{/if}
		<div class="border border-coolgray-500 h-8" />
		{#if $status.application.isExited}
			<a
				href={!$disabledButton ? `/applications/${id}/logs` : null}
				class=" icons bg-transparent tooltip-bottom text-sm flex items-center text-red-500 tooltip-red-500"
				data-tooltip="Application exited with an error!"
				sveltekit:prefetch
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentcolor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path
						d="M8.7 3h6.6c.3 0 .5 .1 .7 .3l4.7 4.7c.2 .2 .3 .4 .3 .7v6.6c0 .3 -.1 .5 -.3 .7l-4.7 4.7c-.2 .2 -.4 .3 -.7 .3h-6.6c-.3 0 -.5 -.1 -.7 -.3l-4.7 -4.7c-.2 -.2 -.3 -.4 -.3 -.7v-6.6c0 -.3 .1 -.5 .3 -.7l4.7 -4.7c.2 -.2 .4 -.3 .7 -.3z"
					/>
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
			</a>
		{/if}
		{#if $status.application.initialLoading}
			<button
				class="icons tooltip-bottom flex animate-spin items-center space-x-2 bg-transparent text-sm duration-500 ease-in-out"
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
					<path d="M9 4.55a8 8 0 0 1 6 14.9m0 -4.45v5h5" />
					<line x1="5.63" y1="7.16" x2="5.63" y2="7.17" />
					<line x1="4.06" y1="11" x2="4.06" y2="11.01" />
					<line x1="4.63" y1="15.1" x2="4.63" y2="15.11" />
					<line x1="7.16" y1="18.37" x2="7.16" y2="18.38" />
					<line x1="11" y1="19.94" x2="11" y2="19.95" />
				</svg>
			</button>
		{:else if $status.application.isRunning}
			<button
				on:click={stopApplication}
				title="Stop application"
				type="submit"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-red-500"
				data-tooltip={$appSession.isAdmin
					? $t('application.stop_application')
					: $t('application.permission_denied_stop_application')}
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
					disabled={$disabledButton || !isQueueActive}
					class:hover:text-green-500={isQueueActive}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2"
					data-tooltip={$appSession.isAdmin
						? isQueueActive
							? 'Rebuild application'
							: 'Autoupdate inprogress. Cannot rebuild application.'
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
					disabled={$disabledButton}
					class="icons bg-transparent tooltip-bottom text-sm flex items-center space-x-2 text-green-500"
					data-tooltip={$appSession.isAdmin
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

		<div class="border border-coolgray-500 h-8" />
		<a
			href={!$disabledButton ? `/applications/${id}` : null}
			sveltekit:prefetch
			class="hover:text-yellow-500 rounded"
			class:text-yellow-500={$page.url.pathname === `/applications/${id}`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}`}
		>
			<button
				title="Configurations"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm"
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
			href={!$disabledButton ? `/applications/${id}/secrets` : null}
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/applications/${id}/secrets`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/secrets`}
		>
			<button
				title="Secret"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm"
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
			href={!$disabledButton ? `/applications/${id}/storages` : null}
			sveltekit:prefetch
			class="hover:text-pink-500 rounded"
			class:text-pink-500={$page.url.pathname === `/applications/${id}/storages`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/storages`}
		>
			<button
				title="Persistent Storages"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm"
				data-tooltip="Persistent Storages"
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
			href={!$disabledButton ? `/applications/${id}/previews` : null}
			sveltekit:prefetch
			class="hover:text-orange-500 rounded"
			class:text-orange-500={$page.url.pathname === `/applications/${id}/previews`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/previews`}
		>
			<button
				title="Previews"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm"
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
		<div class="border border-coolgray-500 h-8" />
		<a
			href={!$disabledButton && $status.application.isRunning ? `/applications/${id}/logs` : null}
			sveltekit:prefetch
			class="hover:text-sky-500 rounded"
			class:text-sky-500={$page.url.pathname === `/applications/${id}/logs`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs`}
		>
			<button
				title={$t('application.logs')}
				disabled={$disabledButton || !$status.application.isRunning}
				class="icons bg-transparent tooltip-bottom text-sm"
				data-tooltip={$t('application.logs')}
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
			href={!$disabledButton ? `/applications/${id}/logs/build` : null}
			sveltekit:prefetch
			class="hover:text-red-500 rounded"
			class:text-red-500={$page.url.pathname === `/applications/${id}/logs/build`}
			class:bg-coolgray-500={$page.url.pathname === `/applications/${id}/logs/build`}
		>
			<button
				title="Build Logs"
				disabled={$disabledButton}
				class="icons bg-transparent tooltip-bottom text-sm"
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
		<div class="border border-coolgray-500 h-8" />

		<button
			on:click={() => deleteApplication(application.name)}
			title={$t('application.delete_application')}
			type="submit"
			disabled={!$appSession.isAdmin}
			class:hover:text-red-500={$appSession.isAdmin}
			class="icons bg-transparent  tooltip-bottom text-sm"
			data-tooltip={$appSession.isAdmin
				? $t('application.delete_application')
				: $t('application.permission_denied_delete_application')}
		>
			<DeleteIcon />
		</button>
	{/if}
</nav>
<slot />
