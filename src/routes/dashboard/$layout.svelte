<script context="module" lang="ts">
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
</script>

<script>
	import { dashboard } from '$store';
	import { onDestroy, onMount } from 'svelte';
	import { session } from '$app/stores';

	let loadDashboardInterval = null;
	export let data;
	$dashboard = data;

	async function loadDashboard() {
		try {
			$dashboard = await request('/api/v1/dashboard', $session);
		} catch (error) {
			//
		}
	}

	onMount(() => {
		loadDashboardInterval = setInterval(async () => {
			await loadDashboard();
		}, 2000);
	});
	onDestroy(() => {
		clearInterval(loadDashboardInterval);
	});
</script>

<div class="min-h-full text-white">
	<slot />
</div>
