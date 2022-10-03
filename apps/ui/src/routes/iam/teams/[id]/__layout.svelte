<script context="module" lang="ts">
	import { del, get } from '$lib/api';
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ params, url }) => {
		try {
			const response = await get(`/iam/team/${params.id}`);
			if (!response.permissions || Object.entries(response.permissions).length === 0) {
				return {
					status: 302,
					redirect: '/iam/teams'
				};
			}
			return {
				props: {
					...response
				},
				stuff: {
					...response
				}
			};
		} catch (error) {
			return handlerNotFoundLoad(error, url);
		}
	};
</script>

<script lang="ts">
	import { handlerNotFoundLoad } from '$lib/common';
</script>

<slot />
