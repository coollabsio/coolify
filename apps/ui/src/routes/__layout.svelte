<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ url }) => {
		const baseSettings = await get('/base');
		try {
			if (Cookies.get('token')) {
				const response = await get(`/user`);
				return {
					props: {
						...response,
						baseSettings
					},
					stuff: {
						...response
					}
				};
			} else {
				if (url.pathname !== '/login' && url.pathname !== '/register') {
					return {
						status: 302,
						redirect: '/login',
						props: {
							baseSettings
						}
					};
				}
				return {
					props: {
						baseSettings
					}
				};
			}
		} catch (error: any) {
			if (error?.code?.startsWith('FAST_JWT') || error.status === 401) {
				Cookies.remove('token');
				if (url.pathname !== '/login') {
					return {
						status: 302,
						redirect: '/login',
						props: {
							baseSettings
						}
					};
				}
			}
			if (url.pathname !== '/login') {
				return {
					status: 302,
					redirect: '/login',
					props: {
						baseSettings
					}
				};
			}
			return {
				status: 500,
				error: new Error(error),
				props: {
					baseSettings
				}
			};
		}
	};
</script>

<script lang="ts">
	export let baseSettings: any;
	$appSession.ipv4 = baseSettings.ipv4;
	$appSession.ipv6 = baseSettings.ipv6;
	$appSession.version = baseSettings.version;
	$appSession.whiteLabeled = baseSettings.whiteLabeled;
	$appSession.whiteLabeledDetails.icon = baseSettings.whiteLabeledIcon;

	export let userId: string;
	export let teamId: string;
	export let permission: string;
	export let isAdmin: boolean;
	import '../tailwind.css';
	import Cookies from 'js-cookie';
	import { fade } from 'svelte/transition';
	import { navigating, page } from '$app/stores';

	import { get } from '$lib/api';
	import UpdateAvailable from '$lib/components/UpdateAvailable.svelte';
	import PageLoader from '$lib/components/PageLoader.svelte';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	import Toasts from '$lib/components/Toasts.svelte';

	if (userId) $appSession.userId = userId;
	if (teamId) $appSession.teamId = teamId;
	if (permission) $appSession.permission = permission;
	if (isAdmin) $appSession.isAdmin = isAdmin;

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
	<title>Coolify</title>
	{#if !$appSession.whiteLabeled}
		<link rel="icon" href="/favicon.png" />
	{:else if $appSession.whiteLabeledDetails.icon}
		<link rel="icon" href={$appSession.whiteLabeledDetails.icon} />
	{/if}
</svelte:head>
<Toasts />
{#if $navigating}
	<div out:fade={{ delay: 100 }}>
		<PageLoader />
	</div>
{/if}
{#if $appSession.userId}
	<nav class="nav-main">
		<div class="flex h-screen w-full flex-col items-center transition-all duration-100">
			{#if !$appSession.whiteLabeled}
				<div class="my-4 h-10 w-10"><img src="/favicon.png" alt="coolLabs logo" /></div>
			{:else if $appSession.whiteLabeledDetails.icon}
				<div class="my-4 h-10 w-10">
					<img src={$appSession.whiteLabeledDetails.icon} alt="White labeled logo" />
				</div>
			{/if}
			<div class="flex flex-col space-y-2 py-2" class:mt-2={$appSession.whiteLabeled}>
				<a
					sveltekit:prefetch
					href="/"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200 hover:text-white"
					class:text-white={$page.url.pathname === '/'}
					class:bg-coolgray-500={$page.url.pathname === '/'}
					data-tip="Dashboard"
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
				</a>
				<div class="border-t border-stone-700" />

				<a
					sveltekit:prefetch
					href="/applications"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-applications={$page.url.pathname.startsWith('/applications') ||
						$page.url.pathname.startsWith('/new/application')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/applications') ||
						$page.url.pathname.startsWith('/new/application')}
					data-tip="Applications"
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="h-8 w-8"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentcolor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<rect x="4" y="4" width="6" height="6" rx="1" />
						<rect x="4" y="14" width="6" height="6" rx="1" />
						<rect x="14" y="14" width="6" height="6" rx="1" />
						<line x1="14" y1="7" x2="20" y2="7" />
						<line x1="17" y1="4" x2="17" y2="10" />
					</svg>
				</a>
				<a
					sveltekit:prefetch
					href="/sources"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-sources={$page.url.pathname.startsWith('/sources') ||
						$page.url.pathname.startsWith('/new/source')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/sources') ||
						$page.url.pathname.startsWith('/new/source')}
					data-tip="Git Sources"
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
						<circle cx="6" cy="6" r="2" />
						<circle cx="18" cy="18" r="2" />
						<path d="M11 6h5a2 2 0 0 1 2 2v8" />
						<polyline points="14 9 11 6 14 3" />
						<path d="M13 18h-5a2 2 0 0 1 -2 -2v-8" />
						<polyline points="10 15 13 18 10 21" />
					</svg>
				</a>
				<a
					sveltekit:prefetch
					href="/destinations"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-destinations={$page.url.pathname.startsWith('/destinations') ||
						$page.url.pathname.startsWith('/new/destination')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/destinations') ||
						$page.url.pathname.startsWith('/new/destination')}
					data-tip="Destinations"
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
							d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
						/>
						<path d="M5 10h3v3h-3z" />
						<path d="M8 10h3v3h-3z" />
						<path d="M11 10h3v3h-3z" />
						<path d="M8 7h3v3h-3z" />
						<path d="M11 7h3v3h-3z" />
						<path d="M11 4h3v3h-3z" />
						<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
						<line x1="10" y1="16" x2="10" y2="16.01" />
					</svg>
				</a>
				<div class="border-t border-stone-700" />
				<a
					sveltekit:prefetch
					href="/databases"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-databases={$page.url.pathname.startsWith('/databases') ||
						$page.url.pathname.startsWith('/new/database')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/databases') ||
						$page.url.pathname.startsWith('/new/database')}
					data-tip="Databases"
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
						<ellipse cx="12" cy="6" rx="8" ry="3" />
						<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
						<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
					</svg>
				</a>
				<a
					sveltekit:prefetch
					href="/services"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-services={$page.url.pathname.startsWith('/services') ||
						$page.url.pathname.startsWith('/new/service')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/services') ||
						$page.url.pathname.startsWith('/new/service')}
					data-tip="Services"
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
						<path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-12" />
					</svg>
				</a>
			</div>
			<div class="flex-1" />

			<UpdateAvailable />
			<div class="flex flex-col space-y-2 py-2">
				<a
					sveltekit:prefetch
					href="/iam"
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-iam={$page.url.pathname.startsWith('/iam')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/iam')}
					data-tip="IAM"
					><svg
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
						<circle cx="9" cy="7" r="4" />
						<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
						<path d="M16 3.13a4 4 0 0 1 0 7.75" />
						<path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
					</svg>
				</a>
				<a
					sveltekit:prefetch
					href={$appSession.teamId === '0' ? '/settings/global' : '/settings/ssh-keys'}
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200"
					class:text-settings={$page.url.pathname.startsWith('/settings')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/settings')}
					data-tip="Settings"
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
							d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"
						/>
						<circle cx="12" cy="12" r="3" />
					</svg>
				</a>

				<div
					class="icons tooltip tooltip-primary tooltip-right bg-coolgray-200 hover:text-error"
					data-tip="Logout"
					on:click={logout}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="ml-1 h-7 w-7"
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
				<div
					class="w-full text-center font-bold text-stone-400 hover:bg-coolgray-200 hover:text-white"
				>
					<a
						class="text-[10px] no-underline"
						href={`https://github.com/coollabsio/coolify/releases/tag/v${$appSession.version}`}
						target="_blank">v{$appSession.version}</a
					>
				</div>
			</div>
		</div>
	</nav>
	{#if $appSession.whiteLabeled}
		<span class="fixed bottom-0 left-[50px] z-50 m-2 px-4 text-xs text-stone-700"
			>Powered by <a href="https://coolify.io" target="_blank">Coolify</a></span
		>
	{/if}
{/if}
<main>
	<slot />
</main>
