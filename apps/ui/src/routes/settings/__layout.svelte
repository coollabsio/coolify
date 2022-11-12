<script context="module" lang="ts">
	import { get } from '$lib/api';
	import { page } from '$app/stores';
	import type { Load } from '@sveltejs/kit';
	import Menu from './_Menu.svelte';
	import LeftSidebar from '$lib/components/LeftSidebar.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
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

<ContextMenu>
	<div class="title">Settings</div>
</ContextMenu>

<br/>
<LeftSidebar>
	<div slot="sidebar"><Menu /></div>
	<div slot="content"><slot /></div>
</LeftSidebar>