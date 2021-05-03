<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	import { request } from '$lib/fetch';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export const load: Load = async (session) => {
		try {
			const data = await request('/api/v1/dashboard', session);
			return {
				props: {
					data
				}
			};
		} catch (error) {
			console.log(error);
			return {
				status: 500,
				error: new Error(`Could not load`)
			};
		}
	};
</script>

<script lang="ts">
	export let data;
</script>

<div>{JSON.stringify(data)}</div>
