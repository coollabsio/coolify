<!-- <script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { request } from '$lib/fetch';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */

	export const load: Load = async (session) => {
		try {
			return {
				props: {
					data: await request('/api/v1/dashboard', session)
				}
			};
		} catch (error) {
			return error;
		}
	};
</script> -->
Configuration