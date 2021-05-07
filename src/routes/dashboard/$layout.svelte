<script lang='ts'>
	import { request } from '$lib/fetch';
	import { dashboard } from '$store';
	import { onDestroy, onMount } from 'svelte';
	import { session } from '$app/stores';

	let loadDashboardInterval = null;

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

<div class="min-h-full">
	<slot />
</div>
