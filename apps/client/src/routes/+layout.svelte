<script lang="ts">
	export let data: LayoutData;
	import type { LayoutData } from './$types';
	export const ssr = false;
	import '../app.postcss';
	import { appSession } from '$lib/store';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import { page } from '$app/stores';
	// import UpdateAvailable from '$lib/components/UpdateAvailable.svelte';
	import Cookies from 'js-cookie';
	import { errorNotification } from '$lib/common';
	import Toasts from '$lib/components/Toasts.svelte';

	let sidedrawerToggler: HTMLInputElement;
	if (data.settings.success) {
		$appSession = {
			...$appSession,
			...data.settings.data
		};
	}

	const closeDrawer = () => (sidedrawerToggler.checked = false);
	async function logout() {
		try {
			Cookies.remove('token');
			return window.location.replace('/login');
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<svelte:head>
	{#if !$appSession.whiteLabeled}
		<title>Coolify</title>
		<link rel="icon" href="/favicon.png" />
	{:else if $appSession.whiteLabeledDetails.icon}
		<title>Coolify</title>
		<link rel="icon" href={$appSession.whiteLabeledDetails.icon} />
	{/if}
</svelte:head>
<Toasts />
<div class="drawer">
	<input id="main-drawer" type="checkbox" class="drawer-toggle" bind:this={sidedrawerToggler} />
	<div class="drawer-content">
		{#if $appSession.userId}
			<Tooltip triggeredBy="#iam" placement="right" color="bg-iam">IAM</Tooltip>
			<Tooltip triggeredBy="#settings" placement="right" color="bg-settings text-black"
				>Settings</Tooltip
			>
			<Tooltip triggeredBy="#documentation" placement="right" color="bg-info">Documentation</Tooltip
			>
			<Tooltip triggeredBy="#logout" placement="right" color="bg-red-600">Logout</Tooltip>
			<nav class="nav-main hidden lg:block z-20">
				<div class="flex h-screen w-full flex-col items-center transition-all duration-100">
					{#if !$appSession.whiteLabeled}
						<div class="mb-2 mt-4 h-10 w-10">
							<img src="/favicon.png" alt="coolLabs logo" />
						</div>
					{:else if $appSession.whiteLabeledDetails.icon}
						<div class="mb-2 mt-4 h-10 w-10">
							<img src={$appSession.whiteLabeledDetails.icon} alt="White labeled logo" />
						</div>
					{/if}
					<div class="flex flex-col space-y-2 py-2" class:mt-2={$appSession.whiteLabeled}>
						<a
							id="dashboard"
							href="/"
							class="icons hover:text-pink-500"
							class:text-pink-500={$page.url.pathname === '/'}
							class:bg-coolgray-500={$page.url.pathname === '/'}
							class:bg-coolgray-200={!($page.url.pathname === '/')}
						>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-9 w-9"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path
									d="M19 8.71l-5.333 -4.148a2.666 2.666 0 0 0 -3.274 0l-5.334 4.148a2.665 2.665 0 0 0 -1.029 2.105v7.2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-7.2c0 -.823 -.38 -1.6 -1.03 -2.105"
								/>
								<path d="M16 15c-2.21 1.333 -5.792 1.333 -8 0" />
							</svg>
						</a>
						{#if $appSession.teamId === '0'}
							<a
								id="servers"
								href="/servers"
								class="icons hover:text-sky-500"
								class:text-sky-500={$page.url.pathname === '/servers'}
								class:bg-coolgray-500={$page.url.pathname === '/servers'}
								class:bg-coolgray-200={!($page.url.pathname === '/servers')}
							>
								<svg
									xmlns="http://www.w3.org/2000/svg"
									class="w-8 h-8 mx-auto"
									viewBox="0 0 24 24"
									stroke-width="1.5"
									stroke="currentColor"
									fill="none"
									stroke-linecap="round"
									stroke-linejoin="round"
								>
									<path stroke="none" d="M0 0h24v24H0z" fill="none" />
									<rect x="3" y="4" width="18" height="8" rx="3" />
									<rect x="3" y="12" width="18" height="8" rx="3" />
									<line x1="7" y1="8" x2="7" y2="8.01" />
									<line x1="7" y1="16" x2="7" y2="16.01" />
								</svg>
							</a>
						{/if}
					</div>
					<Tooltip triggeredBy="#dashboard" placement="right">Dashboard</Tooltip>
					<Tooltip triggeredBy="#servers" placement="right">Servers</Tooltip>
					<div class="flex-1" />
					<div class="lg:block hidden">
						<!-- <UpdateAvailable /> -->
					</div>
					<div class="flex flex-col space-y-2 py-2">
						<a
							id="iam"
							href={$appSession.pendingInvitations.length > 0 ? '/iam/pending' : '/iam'}
							class="icons hover:text-iam indicator"
							class:text-iam={$page.url.pathname.startsWith('/iam')}
							class:bg-coolgray-500={$page.url.pathname.startsWith('/iam')}
						>
							{#if $appSession.pendingInvitations.length > 0}
								<span class="indicator-item rounded-full badge badge-primary mr-2"
									>{$appSession.pendingInvitations.length}</span
								>
							{/if}<svg
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
								class="h-9 w-9"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<circle cx="9" cy="7" r="4" />
								<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
								<path d="M16 3.13a4 4 0 0 1 0 7.75" />
								<path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
							</svg>
						</a>
						<a
							id="settings"
							href={$appSession.teamId === '0' ? '/settings/coolify' : '/settings/docker'}
							class="icons hover:text-settings"
							class:text-settings={$page.url.pathname.startsWith('/settings')}
							class:bg-coolgray-500={$page.url.pathname.startsWith('/settings')}
						>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
								class="h-9 w-9"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path
									d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"
								/>
								<circle cx="12" cy="12" r="3" />
							</svg>
						</a>
						<a
							id="documentation"
							href="https://docs.coollabs.io/coolify/"
							target="_blank"
							rel="noopener noreferrer"
							class="icons hover:text-info"
						>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								class="w-9 h-9"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"
								/>
							</svg>
						</a>

						<!-- svelte-ignore a11y-click-events-have-key-events -->
						<div
							id="logout"
							class="icons bg-coolgray-200 hover:text-error cursor-pointer"
							on:click={logout}
						>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="ml-1 h-8 w-8"
								viewBox="0 0 24 24"
								stroke-width="1.5"
								stroke="currentColor"
								fill="none"
								stroke-linecap="round"
								stroke-linejoin="round"
							>
								<path stroke="none" d="M0 0h24v24H0z" fill="none" />
								<path
									d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"
								/>
								<path d="M7 12h14l-3 -3m0 6l3 -3" />
							</svg>
						</div>
						<!-- <div class="lg:block">
							<LocalePicker/>
						</div> -->
						<div
							class="w-full text-center font-bold text-stone-400 hover:bg-coolgray-200 hover:text-white"
						>
							<a
								class="text-[10px] no-underline"
								href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
								target="_blank noreferrer">v{$appSession.version}</a
							>
						</div>
					</div>
				</div>
			</nav>
			{#if $appSession.whiteLabeled}
				<span class="fixed bottom-0 left-[50px] z-50 m-2 px-4 text-xs text-stone-700"
					>Powered by <a href="https://coolify.io" target="_blank noreferrer">Coolify</a></span
				>
			{/if}
		{/if}
		<div
			class="navbar lg:hidden space-x-2 flex flex-row justify-between bg-coollabs"
			class:hidden={!$appSession.userId}
		>
			<div>
				<label for="main-drawer" class="drawer-button btn btn-square btn-ghost flex-col">
					<span class="burger bg-white" />
					<span class="burger bg-white" />
					<span class="burger bg-white" />
				</label>
				<div class="prose flex flex-row justify-between space-x-1 w-full items-center pr-3">
					{#if !$appSession.whiteLabeled}
						<h3 class="mb-0 text-white">Coolify</h3>
					{/if}
				</div>
			</div>
			<!-- <LocalePicker /> -->
		</div>
		<main>
			<div class={$appSession.userId ? 'lg:pl-16' : null}>
				<slot />
			</div>
		</main>
	</div>
	<div class="drawer-side">
		<label for="main-drawer" class="drawer-overlay w-full" />
		<ul class="menu bg-coolgray-200 w-60 p-2  space-y-3 pt-4 ">
			<li>
				<a
					class="no-underline icons hover:text-white hover:bg-pink-500"
					href="/"
					class:bg-pink-500={$page.url.pathname === '/'}
					on:click={closeDrawer}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="h-8 w-8"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M19 8.71l-5.333 -4.148a2.666 2.666 0 0 0 -3.274 0l-5.334 4.148a2.665 2.665 0 0 0 -1.029 2.105v7.2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-7.2c0 -.823 -.38 -1.6 -1.03 -2.105"
						/>
						<path d="M16 15c-2.21 1.333 -5.792 1.333 -8 0" />
					</svg>
					Dashboard
				</a>
			</li>

			<li>
				<a
					id="servers"
					class="no-underline icons hover:text-white hover:bg-sky-500"
					href="/servers"
					class:bg-sky-500={$page.url.pathname.startsWith('/servers')}
					on:click={closeDrawer}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-8 h-8"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<rect x="3" y="4" width="18" height="8" rx="3" />
						<rect x="3" y="12" width="18" height="8" rx="3" />
						<line x1="7" y1="8" x2="7" y2="8.01" />
						<line x1="7" y1="16" x2="7" y2="16.01" />
					</svg>
					Servers
				</a>
			</li>
			<li>
				<a
					class="no-underline icons hover:text-white hover:bg-iam"
					href="/iam"
					class:bg-iam={$page.url.pathname.startsWith('/iam')}
					on:click={closeDrawer}
					><svg
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 24 24"
						class="h-8 w-8"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<circle cx="9" cy="7" r="4" />
						<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
						<path d="M16 3.13a4 4 0 0 1 0 7.75" />
						<path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
					</svg>
					IAM {#if $appSession.pendingInvitations.length > 0}
						<span class="indicator-item rounded-full badge badge-primary"
							>{$appSession.pendingInvitations.length}</span
						>
					{/if}
				</a>
			</li>
			<li>
				<a
					class="no-underline icons hover:text-black hover:bg-settings"
					href={$appSession.teamId === '0' ? '/settings/coolify' : '/settings/ssh'}
					class:bg-settings={$page.url.pathname.startsWith('/settings')}
					class:text-black={$page.url.pathname.startsWith('/settings')}
					on:click={closeDrawer}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 24 24"
						class="h-8 w-8"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"
						/>
						<circle cx="12" cy="12" r="3" />
					</svg>
					Settings
				</a>
			</li>
			<li class="flex-1 bg-transparent" />
			<div class="block lg:hidden">
				<!-- <UpdateAvailable /> -->
			</div>
			<li>
				<!-- svelte-ignore a11y-click-events-have-key-events -->
				<div class="no-underline icons hover:bg-error" on:click={logout}>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="ml-1 h-8 w-8"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"
						/>
						<path d="M7 12h14l-3 -3m0 6l3 -3" />
					</svg>
					<div class="-ml-1">Logout</div>
				</div>
			</li>
			<li class="w-full">
				<a
					class="text-xs hover:bg-coolgray-200 no-underline hover:text-white text-right"
					href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
					target="_blank noreferrer">v{$appSession.version}</a
				>
			</li>
		</ul>
	</div>
</div>
