<script context="module" lang="ts">
	import { get } from '$lib/api';
	import { page } from '$app/stores';
	import type { Load } from '@sveltejs/kit';
	import Menu from './_Menu.svelte';
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

<div class="mx-auto max-w-screen-2xl px-6 grid grid-cols-1 lg:grid-cols-4">
	<nav class="header flex flex-col px-0">
		<div class="title pb-[3.7rem]">Settings</div>
		<div class="px-4">
			<Menu />
		</div>
	</nav>
	<div class="pt-0 lg:pt-[7rem] px-4 lg:px-7 col-span-0 lg:col-span-3">
		<slot />
	</div>
</div>
