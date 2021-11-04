<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { publicPaths } from '$lib/settings';

	export const load: Load = async ({ fetch, page, session }) => {
		if (!session.token && !publicPaths.includes(page.path)) {
			return {
				status: 302,
				redirect: '/login'
			};
		}
		console.log(session)
		return {};
	};
</script>

<script>
	import '../tailwind.css';
	import { page, session } from '$app/stores';
</script>

{#if $session.token}
	<nav class="nav-main">
		<div class="flex flex-col w-full h-screen items-center transition-all duration-100">
			<div class="w-10 h-10 my-4"><img src="/favicon.png" alt="coolLabs logo" /></div>
			<div class="flex flex-col space-y-4 py-2">
				<a
					sveltekit:prefetch
					href="/"
					class="icons bg-coolgray-200 hover:text-white tooltip-right"
					class:text-white={$page.path === '/'}
					class:bg-coolgray-500={$page.path === '/'}
					data-tooltip="Dashboard"
				>
					<svg
						class="w-8 h-8"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
						xmlns="http://www.w3.org/2000/svg"
					>
						<path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
						/>
					</svg>
				</a>
				<div class="border-t border-warmGray-700" />

				<a
					sveltekit:prefetch
					href="/applications"
					class="icons hover:text-green-500 bg-coolgray-200 tooltip-right"
					class:text-green-500={$page.path.startsWith('/applications')}
					class:bg-coolgray-500={$page.path.startsWith('/applications')}
					data-tooltip="Applications"
				>
					<svg
						class=" w-8 h-8"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
						xmlns="http://www.w3.org/2000/svg"
					>
						<path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"
						/>
					</svg>
				</a>
				<a
					sveltekit:prefetch
					href="/sources"
					class="icons hover:text-orange-500 bg-coolgray-200 tooltip-right"
					class:text-orange-500={$page.path.startsWith('/sources')}
					class:bg-coolgray-500={$page.path.startsWith('/sources')}
					data-tooltip="Git Sources"
				>
					<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 fill-current" viewBox="0 0 24 24"
						><path
							d="m21.62 11.108-8.731-8.729a1.292 1.292 0 0 0-1.823 0L9.257 4.19l2.299 2.3a1.532 1.532 0 0 1 1.939 1.95l2.214 2.217a1.53 1.53 0 0 1 1.583 2.531c-.599.6-1.566.6-2.166 0a1.536 1.536 0 0 1-.337-1.662l-2.074-2.063V14.9c.146.071.286.169.407.29a1.537 1.537 0 0 1 0 2.166 1.536 1.536 0 0 1-2.174 0 1.528 1.528 0 0 1 0-2.164c.152-.15.322-.264.504-.339v-5.49a1.529 1.529 0 0 1-.83-2.008l-2.26-2.271-5.987 5.982c-.5.504-.5 1.32 0 1.824l8.731 8.729a1.286 1.286 0 0 0 1.821 0l8.69-8.689a1.284 1.284 0 0 0 .003-1.822"
						/></svg
					>
				</a>
				<a
					sveltekit:prefetch
					href="/destinations"
					class="icons hover:text-sky-500 bg-coolgray-200 tooltip-right"
					class:text-sky-500={$page.path.startsWith('/destinations')}
					class:bg-coolgray-500={$page.path.startsWith('/destinations')}
					data-tooltip="Destinations"
				>
					<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 fill-current" viewBox="0 0 24 24"
						><path
							d="M20.17 9.82a4.76 4.76 0 0 0-.84.07 3.12 3.12 0 0 0-1.43-2.14l-.28-.16-.19.27a3.7 3.7 0 0 0-.51 1.19 2.84 2.84 0 0 0 .33 2.22 4.11 4.11 0 0 1-1.45.35H2.63a.63.63 0 0 0-.63.62 9.6 9.6 0 0 0 .58 3.39 5 5 0 0 0 2 2.6 8.86 8.86 0 0 0 4.42.95 13.27 13.27 0 0 0 2.42-.18 10.09 10.09 0 0 0 3.19-1.15A8.9 8.9 0 0 0 16.78 16a11.94 11.94 0 0 0 2.13-3.67h.19a3.08 3.08 0 0 0 2.23-.84 2.36 2.36 0 0 0 .59-.87l.08-.22-.2-.16a2.69 2.69 0 0 0-1.63-.42z"
						/><path
							d="M5.61 9.35H3.85a.16.16 0 0 0-.16.15v1.58a.16.16 0 0 0 .16.15h1.76a.16.16 0 0 0 .16-.15V9.5a.16.16 0 0 0-.16-.15zm2.44 0H6.28a.16.16 0 0 0-.16.15v1.58a.16.16 0 0 0 .16.15h1.77a.15.15 0 0 0 .15-.15V9.5a.15.15 0 0 0-.15-.15zm2.47 0H8.75a.15.15 0 0 0-.15.15v1.58a.15.15 0 0 0 .15.15h1.77a.15.15 0 0 0 .15-.15V9.5a.15.15 0 0 0-.15-.15zm.67 0a.15.15 0 0 0-.19.15v1.58a.15.15 0 0 0 .15.15H13a.15.15 0 0 0 .15-.15V9.5a.15.15 0 0 0-.15-.15zM6.28 7.09H8a.16.16 0 0 1 .16.16v1.56A.16.16 0 0 1 8 9H6.28a.15.15 0 0 1-.15-.15V7.24a.15.15 0 0 1 .15-.15zm2.47 0h1.77a.15.15 0 0 1 .15.15v1.57a.16.16 0 0 1-.16.16H8.75a.15.15 0 0 1-.15-.15V7.24a.15.15 0 0 1 .15-.15zm2.44 0H13a.15.15 0 0 1 .15.15v1.57A.15.15 0 0 1 13 9h-1.81a.16.16 0 0 1-.19-.19V7.24a.15.15 0 0 1 .19-.15z"
						/><rect x="11.04" y="4.82" width="2.07" height="1.88" rx=".15" /><path
							d="M13.65 9.35a.15.15 0 0 0-.15.15v1.58a.15.15 0 0 0 .15.15h1.77a.15.15 0 0 0 .15-.15V9.5a.15.15 0 0 0-.15-.15z"
						/></svg
					>
				</a>
				<div class="border-t border-warmGray-700" />
				<a
					sveltekit:prefetch
					href="/databases"
					class="icons hover:text-purple-500 bg-coolgray-200 tooltip-right"
					class:text-purple-500={$page.path.startsWith('/databases')}
					class:bg-coolgray-500={$page.path.startsWith('/databases')}
					data-tooltip="Databases"
				>
					<svg
						class="w-8 h-8"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
						xmlns="http://www.w3.org/2000/svg"
						><path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"
						/></svg
					>
				</a>
				<div class="border-t border-warmGray-700" />
				<a
					sveltekit:prefetch
					href="/services"
					class="icons hover:text-pink-500 bg-coolgray-200 tooltip-right"
					class:text-pink-500={$page.path.startsWith('/services')}
					class:bg-coolgray-500={$page.path.startsWith('/services')}
					data-tooltip="Services"
				>
					<svg
						class="w-8 h-8"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
						xmlns="http://www.w3.org/2000/svg"
						><path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"
						/></svg
					>
				</a>
				<div class="border-t border-warmGray-700" />
				<a
					sveltekit:prefetch
					href="/settings"
					class="icons hover:text-yellow-500 bg-coolgray-200 tooltip-right"
					class:text-yellow-500={$page.path.startsWith('/settings')}
					class:bg-coolgray-500={$page.path.startsWith('/settings')}
					data-tooltip="Settings"
				>
					<svg
						class=" w-8 h-8"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
						xmlns="http://www.w3.org/2000/svg"
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
				</a>
			</div>
			<div class="flex-1" />
			<div
				class="w-full text-warmGray-400 hover:bg-coolgray-200 hover:text-white text-center font-bold"
			>
				<a
					class="text-[10px] no-underline"
					href="https://github.com/coollabsio/coolify/releases/tag/v2.0.0-alpha"
					target="_blank">2.0.0-alpha</a
				>
			</div>
		</div>
	</nav>
{/if}
<main>
	<slot />
</main>
