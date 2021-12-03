<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page }) => {
		const url = `/teams/${page.params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const data = await res.json();
			if (!data.permissions || Object.entries(data.permissions).length === 0) {
				return {
					status: 302,
					redirect: '/teams'
				};
			}
			return {
				stuff: {
					...data
				}
			};
		}

		return {
			status: 302,
			redirect: '/teams'
		};
	};
</script>

<slot></slot>