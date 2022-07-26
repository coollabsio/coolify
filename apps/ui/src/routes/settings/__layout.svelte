<script context="module" lang="ts">
	import { get } from '$lib/api';

	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/settings`);
			return {
				stuff: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>


<slot />
