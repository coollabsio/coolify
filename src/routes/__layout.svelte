<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { publicPaths } from '$lib/settings';

	export const load: Load = async ({ fetch, url, session }) => {
		if (!session.userId && !publicPaths.includes(url.pathname)) {
			return {
				status: 302,
				redirect: '/login'
			};
		}
		if (!session.userId) {
			return {};
		}
		const endpoint = `/dashboard.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
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

	import '../tailwind.css';
	import { SvelteToast, toast } from '@zerodevx/svelte-toast';
	import { page, session } from '$app/stores';
	import { onMount } from 'svelte';
	import { errorNotification } from '$lib/form';
	import { asyncSleep } from '$lib/components/common';
	import { del, get, post } from '$lib/api';
	import { browser, dev } from '$app/env';

	let isUpdateAvailable = false;

	let updateStatus = {
		found: false,
		loading: false,
		success: null
	};
	let latestVersion = 'latest';
	onMount(async () => {
		if ($session.userId) {
			const overrideVersion = browser && window.localStorage.getItem('latestVersion');
			try {
				await get(`/login.json`);
			} catch ({ error }) {
				await del(`/logout.json`, {});
				window.location.reload();
				return errorNotification(error);
			}
			if ($session.teamId === '0') {
				try {
					const data = await get(`/update.json`);
					if (overrideVersion || data?.isUpdateAvailable) {
						latestVersion = overrideVersion || data.latestVersion;
						if (overrideVersion) {
							isUpdateAvailable = true;
						} else {
							isUpdateAvailable = data.isUpdateAvailable;
						}
					}
				} catch (error) {
				} finally {
				}
			}
		}
	});
	async function logout() {
		try {
			await del(`/logout.json`, {});
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function switchTeam() {
		try {
			await post(`/dashboard.json?from=${$page.url.pathname}`, {
				cookie: 'teamId',
				value: selectedTeamId
			});
			return window.location.reload();
		} catch (error) {
			return window.location.reload();
		}
	}

	async function update() {
		updateStatus.loading = true;
		try {
			if (dev) {
				console.log(`updating to ${latestVersion}`);
				await asyncSleep(4000);
				return window.location.reload();
			} else {
				await post(`/update.json`, { type: 'update', latestVersion });
				toast.push('Update completed.<br><br>Waiting for the new version to start...');
				let reachable = false;
				let tries = 0;
				do {
					await asyncSleep(4000);
					try {
						await get(`/undead.json`);
						reachable = true;
					} catch (error) {
						reachable = false;
					}
					if (reachable) break;
					tries++;
				} while (!reachable || tries < 120);
				toast.push('New version reachable. Reloading...');
				updateStatus.loading = false;
				updateStatus.success = true;
				await asyncSleep(3000);
				return window.location.reload();
			}
		} catch ({ error }) {
			updateStatus.success = false;
			updateStatus.loading = false;
			return errorNotification(error);
		}
	}
</script>

<svelte:head>
	<title>Coolify</title>
	{#if !$session.whiteLabeled}
		<link rel="icon" href="/favicon.png" />
	{/if}
</svelte:head>
<SvelteToast options={{ intro: { y: -64 }, duration: 3000, pausable: true }} />
{#if $session.userId}
	<nav class="nav-main">
		<div class="flex h-screen w-full flex-col items-center transition-all duration-100">
			{#if !$session.whiteLabeled}
				<div class="my-4 h-10 w-10"><img src="/favicon.png" alt="coolLabs logo" /></div>
			{/if}
			<div class="flex flex-col space-y-4 py-2" class:mt-2={$session.whiteLabeled}>
				<a
					sveltekit:prefetch
					href="/"
					class="icons tooltip-right bg-coolgray-200 hover:text-white"
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
				<div class="border-t border-stone-700" />

				<a
					sveltekit:prefetch
					href="/applications"
					class="icons tooltip-green-500 tooltip-right bg-coolgray-200 hover:text-green-500"
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
					class="icons tooltip-orange-500 tooltip-right bg-coolgray-200 hover:text-orange-500"
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
				<div class="border-t border-stone-700" />
				<a
					sveltekit:prefetch
					href="/destinations"
					class="icons tooltip-sky-500 tooltip-right bg-coolgray-200 hover:text-sky-500"
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
				<div class="border-t border-stone-700" />
				<a
					sveltekit:prefetch
					href="/databases"
					class="icons tooltip-purple-500 tooltip-right bg-coolgray-200 hover:text-purple-500"
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
				<div class="border-t border-stone-700" />
				<a
					sveltekit:prefetch
					href="/services"
					class="icons tooltip-pink-500 tooltip-right bg-coolgray-200 hover:text-pink-500"
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
			</div>
			<div class="flex-1" />

			<div class="flex flex-col space-y-4 py-2">
				{#if $session.teamId === '0'}
					{#if isUpdateAvailable}
						<button
							disabled={updateStatus.success === false}
							title="Update available"
							on:click={update}
							class="icons tooltip-right bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 text-white duration-75 hover:scale-105"
						>
							{#if updateStatus.loading}
								<svg
									xmlns="http://www.w3.org/2000/svg"
									class="lds-heart h-9 w-8"
									viewBox="0 0 24 24"
									stroke-width="1.5"
									stroke="currentColor"
									fill="none"
									stroke-linecap="round"
									stroke-linejoin="round"
								>
									<path stroke="none" d="M0 0h24v24H0z" fill="none" />
									<path
										d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572"
									/>
								</svg>
							{:else if updateStatus.success === null}
								<svg
									xmlns="http://www.w3.org/2000/svg"
									class="h-9 w-8"
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
								</svg>
							{:else if updateStatus.success}
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" class="h-9 w-8"
									><path
										fill="#DD2E44"
										d="M11.626 7.488c-.112.112-.197.247-.268.395l-.008-.008L.134 33.141l.011.011c-.208.403.14 1.223.853 1.937.713.713 1.533 1.061 1.936.853l.01.01L28.21 24.735l-.008-.009c.147-.07.282-.155.395-.269 1.562-1.562-.971-6.627-5.656-11.313-4.687-4.686-9.752-7.218-11.315-5.656z"
									/><path
										fill="#EA596E"
										d="M13 12L.416 32.506l-.282.635.011.011c-.208.403.14 1.223.853 1.937.232.232.473.408.709.557L17 17l-4-5z"
									/><path
										fill="#A0041E"
										d="M23.012 13.066c4.67 4.672 7.263 9.652 5.789 11.124-1.473 1.474-6.453-1.118-11.126-5.788-4.671-4.672-7.263-9.654-5.79-11.127 1.474-1.473 6.454 1.119 11.127 5.791z"
									/><path
										fill="#AA8DD8"
										d="M18.59 13.609c-.199.161-.459.245-.734.215-.868-.094-1.598-.396-2.109-.873-.541-.505-.808-1.183-.735-1.862.128-1.192 1.324-2.286 3.363-2.066.793.085 1.147-.17 1.159-.292.014-.121-.277-.446-1.07-.532-.868-.094-1.598-.396-2.11-.873-.541-.505-.809-1.183-.735-1.862.13-1.192 1.325-2.286 3.362-2.065.578.062.883-.057 1.012-.134.103-.063.144-.123.148-.158.012-.121-.275-.446-1.07-.532-.549-.06-.947-.552-.886-1.102.059-.549.55-.946 1.101-.886 2.037.219 2.973 1.542 2.844 2.735-.13 1.194-1.325 2.286-3.364 2.067-.578-.063-.88.057-1.01.134-.103.062-.145.123-.149.157-.013.122.276.446 1.071.532 2.037.22 2.973 1.542 2.844 2.735-.129 1.192-1.324 2.286-3.362 2.065-.578-.062-.882.058-1.012.134-.104.064-.144.124-.148.158-.013.121.276.446 1.07.532.548.06.947.553.886 1.102-.028.274-.167.511-.366.671z"
									/><path
										fill="#77B255"
										d="M30.661 22.857c1.973-.557 3.334.323 3.658 1.478.324 1.154-.378 2.615-2.35 3.17-.77.216-1.001.584-.97.701.034.118.425.312 1.193.095 1.972-.555 3.333.325 3.657 1.479.326 1.155-.378 2.614-2.351 3.17-.769.216-1.001.585-.967.702.033.117.423.311 1.192.095.53-.149 1.084.16 1.233.691.148.532-.161 1.084-.693 1.234-1.971.555-3.333-.323-3.659-1.479-.324-1.154.379-2.613 2.353-3.169.77-.217 1.001-.584.967-.702-.032-.117-.422-.312-1.19-.096-1.974.556-3.334-.322-3.659-1.479-.325-1.154.378-2.613 2.351-3.17.768-.215.999-.585.967-.701-.034-.118-.423-.312-1.192-.096-.532.15-1.083-.16-1.233-.691-.149-.53.161-1.082.693-1.232z"
									/><path
										fill="#AA8DD8"
										d="M23.001 20.16c-.294 0-.584-.129-.782-.375-.345-.432-.274-1.061.156-1.406.218-.175 5.418-4.259 12.767-3.208.547.078.927.584.849 1.131-.078.546-.58.93-1.132.848-6.493-.922-11.187 2.754-11.233 2.791-.186.148-.406.219-.625.219z"
									/><path
										fill="#77B255"
										d="M5.754 16c-.095 0-.192-.014-.288-.042-.529-.159-.829-.716-.67-1.245 1.133-3.773 2.16-9.794.898-11.364-.141-.178-.354-.353-.842-.316-.938.072-.849 2.051-.848 2.071.042.551-.372 1.031-.922 1.072-.559.034-1.031-.372-1.072-.923-.103-1.379.326-4.035 2.692-4.214 1.056-.08 1.933.287 2.552 1.057 2.371 2.951-.036 11.506-.542 13.192-.13.433-.528.712-.958.712z"
									/><circle fill="#5C913B" cx="25.5" cy="9.5" r="1.5" /><circle
										fill="#9266CC"
										cx="2"
										cy="18"
										r="2"
									/><circle fill="#5C913B" cx="32.5" cy="19.5" r="1.5" /><circle
										fill="#5C913B"
										cx="23.5"
										cy="31.5"
										r="1.5"
									/><circle fill="#FFCC4D" cx="28" cy="4" r="2" /><circle
										fill="#FFCC4D"
										cx="32.5"
										cy="8.5"
										r="1.5"
									/><circle fill="#FFCC4D" cx="29.5" cy="12.5" r="1.5" /><circle
										fill="#FFCC4D"
										cx="7.5"
										cy="23.5"
										r="1.5"
									/></svg
								>
							{:else}
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" class="h-9 w-8"
									><path
										fill="#FFCC4D"
										d="M36 18c0 9.941-8.059 18-18 18S0 27.941 0 18 8.059 0 18 0s18 8.059 18 18"
									/><path
										fill="#664500"
										d="M22 27c0 2.763-1.791 3-4 3-2.21 0-4-.237-4-3 0-2.761 1.79-6 4-6 2.209 0 4 3.239 4 6zm8-12c-.124 0-.25-.023-.371-.072-5.229-2.091-7.372-5.241-7.461-5.374-.307-.46-.183-1.081.277-1.387.459-.306 1.077-.184 1.385.274.019.027 1.93 2.785 6.541 4.629.513.206.763.787.558 1.3-.157.392-.533.63-.929.63zM6 15c-.397 0-.772-.238-.929-.629-.205-.513.044-1.095.557-1.3 4.612-1.844 6.523-4.602 6.542-4.629.308-.456.929-.577 1.387-.27.457.308.581.925.275 1.383-.089.133-2.232 3.283-7.46 5.374C6.25 14.977 6.124 15 6 15z"
									/><path fill="#5DADEC" d="M24 16h4v19l-4-.046V16zM8 35l4-.046V16H8v19z" /><path
										fill="#664500"
										d="M14.999 18c-.15 0-.303-.034-.446-.105-3.512-1.756-7.07-.018-7.105 0-.495.249-1.095.046-1.342-.447-.247-.494-.047-1.095.447-1.342.182-.09 4.498-2.197 8.895 0 .494.247.694.848.447 1.342-.176.35-.529.552-.896.552zm14 0c-.15 0-.303-.034-.446-.105-3.513-1.756-7.07-.018-7.105 0-.494.248-1.094.047-1.342-.447-.247-.494-.047-1.095.447-1.342.182-.09 4.501-2.196 8.895 0 .494.247.694.848.447 1.342-.176.35-.529.552-.896.552z"
									/><ellipse fill="#5DADEC" cx="18" cy="34" rx="18" ry="2" /><ellipse
										fill="#E75A70"
										cx="18"
										cy="27"
										rx="3"
										ry="2"
									/></svg
								>
							{/if}
						</button>
					{/if}
				{/if}
			</div>
			<div class="flex flex-col space-y-4 py-2">
				<a
					sveltekit:prefetch
					href="/iam"
					class="icons tooltip-right bg-coolgray-200 hover:text-fuchsia-500"
					class:text-fuchsia-500={$page.url.pathname.startsWith('/iam')}
					class:bg-coolgray-500={$page.url.pathname.startsWith('/iam')}
					data-tooltip="IAM"
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

				{#if $session.teamId === '0'}
					<a
						sveltekit:prefetch
						href="/settings"
						class="icons tooltip-yellow-500 tooltip-right bg-coolgray-200 hover:text-yellow-500"
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
					class="icons tooltip-red-500 tooltip-right bg-coolgray-200 hover:text-red-500"
					data-tooltip="Logout"
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
						href={`https://github.com/coollabsio/coolify/releases/tag/v${$session.version}`}
						target="_blank">v{$session.version}</a
					>
				</div>
			</div>
		</div>
	</nav>
	{#if $session.whiteLabeled}
		<span class="fixed  bottom-0 left-[50px] z-50 m-2 px-4 text-xs text-stone-700"
			>Powered by <a href="https://coolify.io" target="_blank">Coolify</a></span
		>
	{/if}

	<select
		class="fixed right-0 bottom-0 z-50 m-2 w-64 bg-opacity-30 p-2 px-4 hover:bg-opacity-100"
		bind:value={selectedTeamId}
		on:change={switchTeam}
	>
		<option value="" disabled selected>Switch to a different team...</option>
		{#each teams as team}
			<option value={team.teamId}>{team.team.name} - {team.permission}</option>
		{/each}
	</select>
{/if}
<main>
	<slot />
</main>
