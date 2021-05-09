<script context="module" lang="ts">
	import { request } from '$lib/request';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load(session) {
		return {
			props: {
				initDashboard: await request('/api/v1/dashboard', session)
			}
		};
	}
</script>

<script lang="ts">
	export let initDashboard;
	import { dashboard } from '$store';
	import { onDestroy, onMount } from 'svelte';
	import { session } from '$app/stores';
	$dashboard = initDashboard;
	let loadDashboardInterval = null;

	async function loadDashboard() {
		try {
			$dashboard = await request('/api/v1/dashboard', $session);
		} catch (error) {
			//
		}
	}

	onMount(async () => {
		await loadDashboard();
		loadDashboardInterval = setInterval(async () => {
			await loadDashboard();
		}, 2000);
	});
	onDestroy(() => {
		clearInterval(loadDashboardInterval);
	});
</script>

<div class="min-h-full">
	<slot />
</div>
