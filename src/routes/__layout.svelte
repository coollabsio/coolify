<script context="module" lang="ts">
	import { publicPages } from '$lib/consts';
	import { request } from '$lib/request';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load(session) {
		const { path } = session.page;
		if (!publicPages.includes(path)) {
			if (!session.session.isLoggedIn) {
				return {
					status: 301,
					redirect: '/'
				};
			}
			return {};
		}
		if (!publicPages.includes(path)) {
			return {
				status: 301,
				redirect: '/'
			};
		}
		return {};
	}
</script>

<script lang="ts">
	import '../app.postcss';
	export let initDashboard;
	import { onMount } from 'svelte';
	import { SvelteToast } from '@zerodevx/svelte-toast';
	import { goto } from '$app/navigation';
	import { page, session } from '$app/stores';
	import { toast } from '@zerodevx/svelte-toast';
	import Tooltip from '$components/Tooltip.svelte';
	import compareVersions from 'compare-versions';
	import packageJson from '../../package.json';
	import { dashboard, settings } from '$store';
	import { browser } from '$app/env';
	$settings.clientId = import.meta.env.VITE_GITHUB_APP_CLIENTID || null;
	$dashboard = initDashboard;
	const branch =
		process.env.NODE_ENV === 'production' &&
		browser &&
		window.location.hostname !== 'test.andrasbacsai.dev'
			? 'main'
			: 'next';
	let latest = {
		coolify: {}
	};
	let upgradeAvailable = false;
	let upgradeDisabled = false;
	let upgradeDone = false;
	let showAck = false;
	let globalFeatureFlag = browser && localStorage.getItem('globalFeatureFlag');
	const options = {
		duration: 2000
	};
	onMount(async () => {
		upgradeAvailable = await checkUpgrade();
		browser && localStorage.removeItem('token');
		if (!localStorage.getItem('automaticErrorReportsAck')) {
			showAck = true;
			if (latest?.coolify[branch]?.settings?.sendErrors) {
				const settings = {
					sendErrors: true
				};
				await request('/api/v1/settings', $session, { body: { ...settings } });
			}
		}
	});
	async function checkUpgrade() {
		latest = await fetch(`https://get.coollabs.io/version.json`, {
			cache: 'no-cache'
		}).then((r) => r.json());

		return compareVersions(latest.coolify[branch].version, packageJson.version) === 1
			? true
			: false;
	}
	async function upgrade() {
		try {
			upgradeDisabled = true;
			await request('/api/v1/upgrade', $session);
			upgradeDone = true;
		} catch (error) {
			browser &&
				toast.push(
					'Something happened during update. Ooops. Automatic error reporting will happen soon.'
				);
		}
	}
	async function logout() {
		await request('/api/v1/logout', $session, { body: {}, method: 'DELETE' });
		location.reload();
	}
	function reloadInAMin() {
		setTimeout(() => {
			location.reload();
		}, 30000);
	}
	function ackError() {
		localStorage.setItem('automaticErrorReportsAck', 'true');
		showAck = false;
	}
</script>

<SvelteToast {options} />

{#if showAck && $page.path !== '/success' && $page.path !== '/'}
	<div class="p-2 fixed top-0 right-0 z-50 w-64 m-2 rounded border-gradient-full bg-black">
		<div class="text-white text-xs space-y-2 text-justify font-medium">
			<div>We implemented an automatic error reporting feature, which is enabled by default.</div>
			<div>Why? Because we would like to hunt down bugs faster and easier.</div>
			<div class="py-5">
				If you do not like it, you can turn it off in the <button
					class="underline font-bold"
					on:click={() => goto('/settings')}>Settings menu</button
				>.
			</div>
			<button
				class="button p-2 bg-warmGray-800 w-full text-center hover:bg-warmGray-700"
				on:click={ackError}>OK</button
			>
		</div>
	</div>
{/if}
<main class:main={$page.path !== '/success' && $page.path !== '/'}>
	{#if $page.path !== '/' && $page.path !== '/success'}
		<nav class="w-16 bg-warmGray-800 text-white top-0 left-0 fixed min-w-4rem min-h-screen">
			<div
				class="flex flex-col w-full h-screen items-center transition-all duration-100"
				class:border-green-500={$page.path === '/dashboard/applications'}
				class:border-purple-500={$page.path === '/dashboard/databases'}
			>
				<div class="w-10 pt-4 pb-4"><img src="/favicon.png" alt="coolLabs logo" /></div>

				{#if $settings.clientId}
					<Tooltip position="right" label="Applications">
						<div
							class="p-2 hover:bg-warmGray-700 rounded hover:text-green-500 mt-4 transition-all duration-100 cursor-pointer"
							on:click={() => goto('/dashboard/applications')}
							class:text-green-500={$page.path === '/dashboard/applications' ||
								$page.path.startsWith('/application')}
							class:bg-warmGray-700={$page.path === '/dashboard/applications' ||
								$page.path.startsWith('/application')}
						>
							<svg
								class="w-8"
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								stroke-linejoin="round"
								><rect x="4" y="4" width="16" height="16" rx="2" ry="2" /><rect
									x="9"
									y="9"
									width="6"
									height="6"
								/><line x1="9" y1="1" x2="9" y2="4" /><line x1="15" y1="1" x2="15" y2="4" /><line
									x1="9"
									y1="20"
									x2="9"
									y2="23"
								/><line x1="15" y1="20" x2="15" y2="23" /><line
									x1="20"
									y1="9"
									x2="23"
									y2="9"
								/><line x1="20" y1="14" x2="23" y2="14" /><line x1="1" y1="9" x2="4" y2="9" /><line
									x1="1"
									y1="14"
									x2="4"
									y2="14"
								/></svg
							>
						</div>
					</Tooltip>
					<Tooltip position="right" label="Databases">
						<div
							class="p-2 hover:bg-warmGray-700 rounded hover:text-purple-500 my-4 transition-all duration-100 cursor-pointer"
							on:click={() => goto('/dashboard/databases')}
							class:text-purple-500={$page.path === '/dashboard/databases' ||
								$page.path.startsWith('/database')}
							class:bg-warmGray-700={$page.path === '/dashboard/databases' ||
								$page.path.startsWith('/database')}
						>
							<svg
								class="w-8"
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"
								/>
							</svg>
						</div>
					</Tooltip>
				{:else}
					<Tooltip
						position="right"
						label="Applications disabled, no GitHub Integration detected"
						size="large"
					>
						<div class="p-2 text-warmGray-700 mt-4 transition-all duration-100 cursor-pointer">
							<svg
								class="w-8"
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								stroke-linejoin="round"
								><rect x="4" y="4" width="16" height="16" rx="2" ry="2" /><rect
									x="9"
									y="9"
									width="6"
									height="6"
								/><line x1="9" y1="1" x2="9" y2="4" /><line x1="15" y1="1" x2="15" y2="4" /><line
									x1="9"
									y1="20"
									x2="9"
									y2="23"
								/><line x1="15" y1="20" x2="15" y2="23" /><line
									x1="20"
									y1="9"
									x2="23"
									y2="9"
								/><line x1="20" y1="14" x2="23" y2="14" /><line x1="1" y1="9" x2="4" y2="9" /><line
									x1="1"
									y1="14"
									x2="4"
									y2="14"
								/></svg
							>
						</div>
					</Tooltip>
					<Tooltip position="right" label="Databases disabled, no GitHub Integration detected" size="large">
						<div
							class="p-2 text-warmGray-700 my-4 transition-all duration-100 cursor-pointer"
						>
							<svg
								class="w-8"
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"
								/>
							</svg>
						</div>
					</Tooltip>
				{/if}

				<Tooltip position="right" label="Services">
					<div
						class="p-2 hover:bg-warmGray-700 rounded hover:text-blue-500 transition-all duration-100 cursor-pointer"
						class:text-blue-500={$page.path === '/dashboard/services' ||
							$page.path.startsWith('/service')}
						class:bg-warmGray-700={$page.path === '/dashboard/services' ||
							$page.path.startsWith('/service')}
						on:click={() => goto('/dashboard/services')}
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="w-8"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"
							/>
						</svg>
					</div>
				</Tooltip>
				<div class="flex-1" />
				{#if globalFeatureFlag}
					<Tooltip position="right" label="Servers">
						<div
							class="p-2 hover:bg-warmGray-700 rounded hover:text-red-500 mb-4 transition-all duration-100 cursor-pointer"
							on:click={() => goto('/servers')}
							class:text-red-500={$page.path === '/servers' || $page.path.startsWith('/servers')}
							class:bg-warmGray-700={$page.path === '/servers' || $page.path.startsWith('/servers')}
						>
							<svg
								class="w-8"
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"
								/>
							</svg>
						</div>
					</Tooltip>
				{/if}
				<Tooltip position="right" label="Settings">
					<button
						class="p-2 hover:bg-warmGray-700 rounded hover:text-yellow-500 transition-all duration-100 cursor-pointer"
						class:text-yellow-500={$page.path === '/settings'}
						class:bg-warmGray-700={$page.path === '/settings'}
						on:click={() => goto('/settings')}
					>
						<svg
							class="w-8"
							xmlns="http://www.w3.org/2000/svg"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
							/>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
							/>
						</svg>
					</button>
				</Tooltip>
				<Tooltip position="right" label="Logout">
					<button
						class="p-2 hover:bg-warmGray-700 rounded hover:text-red-500 my-4 transition-all duration-100 cursor-pointer"
						on:click={logout}
					>
						<svg
							class="w-7"
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline
								points="16 17 21 12 16 7"
							/><line x1="21" y1="12" x2="9" y2="12" /></svg
						>
					</button>
				</Tooltip>
				<a
					href={`https://github.com/coollabsio/coolify/releases/tag/v${packageJson.version}`}
					target="_blank"
					class="cursor-pointer text-xs font-bold text-warmGray-400 py-2 hover:bg-warmGray-700 w-full text-center"
				>
					{packageJson.version}
				</a>
			</div>
		</nav>
	{/if}
	<slot />
</main>
{#if upgradeAvailable && $page.path !== '/success' && $page.path !== '/'}
	<footer
		class="fixed bottom-0 right-0 p-4 px-6 w-auto rounded-tl text-white  hover:scale-110  transition duration-100"
	>
		<div class="flex items-center">
			<div />
			<div class="flex-1" />
			{#if !upgradeDisabled}
				<button
					class="bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 text-xs font-bold rounded px-2 py-2"
					disabled={upgradeDisabled}
					on:click={upgrade}>New version available, <br />click here to upgrade!</button
				>
			{:else if upgradeDone}
				<button
					use:reloadInAMin
					class="font-bold text-xs rounded px-2 cursor-not-allowed"
					disabled={upgradeDisabled}>Upgrade done. 🎉 Automatically reloading in 30s.</button
				>
			{:else}
				<button
					class="opacity-50 tracking-tight font-bold text-xs rounded px-2 cursor-not-allowed"
					disabled={upgradeDisabled}>Upgrading. It could take a while, please wait...</button
				>
			{/if}
		</div>
	</footer>
{/if}
