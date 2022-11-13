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
	export let pendingInvitations: any = 0;

	$appSession.isRegistrationEnabled = baseSettings.isRegistrationEnabled;
	$appSession.ipv4 = baseSettings.ipv4;
	$appSession.ipv6 = baseSettings.ipv6;
	$appSession.version = baseSettings.version;
	$appSession.whiteLabeled = baseSettings.whiteLabeled;
	$appSession.whiteLabeledDetails.icon = baseSettings.whiteLabeledIcon;

	$appSession.pendingInvitations = pendingInvitations;

	export let userId: string;
	export let teamId: string;
	export let permission: string;
	export let isAdmin: boolean;

	import { status, io } from '$lib/store';
	import '../tailwind.css';
	import Cookies from 'js-cookie';
	import { fade } from 'svelte/transition';
	import { navigating, page } from '$app/stores';

	import { get } from '$lib/api';
	import PageLoader from '$lib/components/PageLoader.svelte';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	import Toasts from '$lib/components/Toasts.svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	// import MainNavigation from '$lib/components/MainNavigation.svelte';
	import MainNavigationNew from '$lib/components/MainNavigationNew.svelte';
	import { onMount } from 'svelte';
	import Drawer from '$lib/components/mobile/Drawer.svelte';

	if (userId) $appSession.userId = userId;
	if (teamId) $appSession.teamId = teamId;
	if (permission) $appSession.permission = permission;
	if (isAdmin) $appSession.isAdmin = isAdmin;

	onMount(async () => {
		io.connect();
		io.on('start-service', (message) => {
			const { serviceId, state } = message;
			$status.service.startup[serviceId] = state;
			if (state === 0 || state === 1) {
				delete $status.service.startup[serviceId];
			}
		});
	});
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
{#if $navigating}
	<div out:fade={{ delay: 100 }}>
		<PageLoader />
	</div>
{/if}

<MainNavigationNew/>
<Drawer>
	<div slot="drawer-content"><slot/></div>
</Drawer>

<Tooltip triggeredBy="#iam" placement="right" color="bg-iam">IAM</Tooltip>
<Tooltip triggeredBy="#settings" placement="right" color="bg-settings text-black">Settings</Tooltip>
<Tooltip triggeredBy="#logout" placement="right" color="bg-red-600">Logout</Tooltip>
