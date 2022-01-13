<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { publicPaths } from '$lib/settings';

	export const load: Load = async ({ fetch, url, params, session }) => {
		const currentRoute = url.pathname;
		if (!session.uid && !publicPaths.includes(url.pathname)) {
			return {
				status: 302,
				redirect: '/login'
			};
		}
		if (!session.uid) {
			return {};
		}
		const endpoint = `/teams.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					currentRoute,
					selectedTeamId: session.teamId,
					...(await res.json())
				}
			};
		}
		return {};
	};
</script>

<script>
	export let teams;
	export let selectedTeamId;
	export let currentRoute;
	import { fade } from 'svelte/transition';

	import '../tailwind.css';
	import { SvelteToast } from '@zerodevx/svelte-toast';
	import { page, session } from '$app/stores';
	import { browser, dev } from '$app/env';
	import { onMount } from 'svelte';
	import { errorNotification } from '$lib/form';
	let alpha = true;
	let isUpdateAvailable = false;
	let latestVersion = null;

	onMount(async () => {
		if ($session.uid) {
			const response = await fetch(`/login.json`, {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json'
				}
			});
			if (!response.ok) {
				await fetch(`/logout.json`, {
					method: 'delete'
				});
				browser && window.location.reload();
			}
		}
		if (!dev) {
			const response = await fetch(`/update.json`, {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json'
				}
			});
			const data = await response.json();
			isUpdateAvailable = data.isUpdateAvailable;
			latestVersion = data.latestVersion;
		}
	});
	async function logout() {
		await fetch(`/logout.json`, {
			method: 'delete'
		});
		window.location.reload();
	}
	async function switchTeam() {
		const form = new FormData();
		form.append('cookie', 'teamId');
		form.append('value', selectedTeamId);
		const response = await fetch(`/index.json?from=${$page.url.pathname}`, {
			method: 'post',
			body: form
		});
		if (!response.ok) {
			const { message } = await response.json();
			errorNotification(message);
			return;
		}
		window.location.reload();
	}

	async function update() {
		const response = await fetch(`/update.json`, {
			method: 'post'
		});
		if (!response.ok) {
			const { message } = await response.json();
			errorNotification(message);
			return;
		}
		// TODO: wait 20 sec and reload
	}
</script>

<SvelteToast options={{ intro: { y: -64 }, duration: 2000, pausable: true }} />
{#if $session.uid}
	<nav class="nav-main">
		<div class="flex flex-col w-full h-screen items-center transition-all duration-100">
			<div class="w-10 h-10 my-4"><img src="/favicon.png" alt="coolLabs logo" /></div>
			<div class="flex flex-col space-y-4 py-2">
				<a
					sveltekit:prefetch
					href="/"
					class="icons bg-coolgray-200 hover:text-white tooltip-right"
					class:text-white={$page.url.pathname === '/'}
					class:bg-coolgray-500={$page.url.pathname === '/'}
					data-tooltip="Dashboard"
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
				<div class="border-t border-warmGray-700" />

				<a
					sveltekit:prefetch
					href="/applications"
					class="icons hover:text-green-500 bg-coolgray-200 tooltip-right"
					class:text-green-500={$page.url.pathname.startsWith('/applications') ||
						$page.url.pathname.startsWith('/new/application')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/applications') ||
						$page.url.pathname.startsWith('/new/application')}
					data-tooltip="Applications"
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
					class="icons hover:text-orange-500 bg-coolgray-200 tooltip-right"
					class:text-orange-500={$page.url.pathname.startsWith('/sources') ||
						$page.url.pathname.startsWith('/new/source')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/sources') ||
						$page.url.pathname.startsWith('/new/source')}
					data-tooltip="Git Sources"
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
					class="icons hover:text-sky-500 bg-coolgray-200 tooltip-right"
					class:text-sky-500={$page.url.pathname.startsWith('/destinations') ||
						$page.url.pathname.startsWith('/new/destination')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/destinations') ||
						$page.url.pathname.startsWith('/new/destination')}
					data-tooltip="Destinations"
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
				<div class="border-t border-warmGray-700" />
				<a
					sveltekit:prefetch
					href="/databases"
					class="icons hover:text-purple-500 bg-coolgray-200 tooltip-right"
					class:text-purple-500={$page.url.pathname.startsWith('/databases') ||
						$page.url.pathname.startsWith('/new/database')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/databases') ||
						$page.url.pathname.startsWith('/new/database')}
					data-tooltip="Databases"
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
				<div class="border-t border-warmGray-700" />
				<a
					sveltekit:prefetch
					href="/services"
					class="icons hover:text-pink-500 bg-coolgray-200 tooltip-right"
					class:text-pink-500={$page.url.pathname.startsWith('/services') ||
						$page.url.pathname.startsWith('/new/service')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/services') ||
						$page.url.pathname.startsWith('/new/service')}
					data-tooltip="Services"
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
				<div class="border-t border-warmGray-700" />
			</div>
			<div class="flex-1" />

			<div class="flex flex-col space-y-4 py-2">
				{#if isUpdateAvailable}
					<button
						data-tooltip="Update available"
						on:click={update}
						class="icons text-white bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 hover:scale-105 duration-75 tooltip-right"
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
							<circle cx="12" cy="12" r="9" />
							<line x1="12" y1="8" x2="8" y2="12" />
							<line x1="12" y1="8" x2="12" y2="16" />
							<line x1="16" y1="12" x2="12" y2="8" />
						</svg></button
					>
				{/if}
				<a
					sveltekit:prefetch
					href="/teams"
					class="icons hover:text-cyan-500 bg-coolgray-200 tooltip-right"
					class:text-cyan-500={$page.url.pathname.startsWith('/teams')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/teams')}
					data-tooltip="Teams"
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
						<circle cx="9" cy="7" r="4" />
						<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
						<path d="M16 3.13a4 4 0 0 1 0 7.75" />
						<path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
					</svg>
				</a>
				{#if $session.teamId === '0'}
					<a
						sveltekit:prefetch
						href="/settings"
						class="icons hover:text-yellow-500 bg-coolgray-200 tooltip-right"
						class:text-yellow-500={$page.url.pathname.startsWith('/settings')}
						class:bg-coolgray-500={$page.url.pathname.startsWith('/settings')}
						data-tooltip="Settings"
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
				{/if}
				<div
					class="icons hover:text-red-500 bg-coolgray-200 tooltip-right"
					data-tooltip="Logout"
					on:click={logout}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-7 h-7 ml-1"
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
			</div>
			<div
				class="w-full text-warmGray-400 hover:bg-coolgray-200 hover:text-white text-center font-bold"
			>
				<a
					class="text-[10px] no-underline"
					href={`https://github.com/coollabsio/coolify/releases/tag/v${$session.version}`}
					target="_blank">v{$session.version}</a
				>
			</div>
		</div>
	</nav>
	<select
		class="fixed right-0 bottom-0 p-2 px-4 m-2 z-50"
		bind:value={selectedTeamId}
		on:change={switchTeam}
	>
		<option value="" disabled selected>Switch to a different team...</option>
		{#each teams as team}
			<option value={team.teamId}>{team.team.name} - {team.permission}</option>
		{/each}
	</select>
{/if}
{#key currentRoute}
	<main in:fade={{ duration: 150 }}>
		<slot />
	</main>
{/key}
