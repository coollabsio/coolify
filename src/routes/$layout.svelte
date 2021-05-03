<script context="module" lang="ts">
	const publicPages = ['/'];
	import { checkAuth } from '$lib/checkAuth';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load({ page, session }) {
		if (!publicPages.includes(page.path)) {
			return checkAuth({ session });
		}
		return {};
	}
</script>

<script lang="ts">
	import '../app.postcss';
</script>

<main>
	<slot />
</main>
