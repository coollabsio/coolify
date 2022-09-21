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

<div class="flex flex-col lg:flex-row ">
	<nav class="header flex flex-col w-full lg:w-52">
		<div class="title pb-10">Settings</div>
		<Menu />
	</nav>
	<div class="pt-0 lg:pt-24 px-5 lg:px-0 mx-auto">
		<slot />
	</div>
</div>
