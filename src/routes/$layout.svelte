<script context="module" lang="ts">
	const publicPages = ['/'];
	import { checkAuth } from '$lib/checkAuth';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load({ page, session }) {
		const { path } = page;
		if (!publicPages.includes(path)) {
			return checkAuth({ session, path });
		}
		return {
			props: {
				path: page.path
			}
		};
	}
</script>

<script lang="ts">
	export let path;
	import { SvelteToast } from '@zerodevx/svelte-toast';
	import '../app.postcss';
	const options = {
		duration: 2000
	};
</script>

<SvelteToast {options} />

<main>
	{#if path !== '/' && path !== '/bye'}
		<nav>
			<a href="/">Home</a>
			<a href="/dashboard/applications">Applications</a>
		</nav>
	{/if}
	<slot />
</main>
