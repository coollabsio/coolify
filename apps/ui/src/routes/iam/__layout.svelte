<script lang="ts">
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { appSession } from '$lib/store';
	import Menu from './_Menu.svelte';
</script>

<div class="mx-auto max-w-screen-2xl px-6 grid grid-cols-1 lg:grid-cols-2">
	<nav class="header flex flex-row order-2 lg:order-1 px-0 lg:px-4 items-start">
		<div class="title lg:pb-10">Identity & Access Management</div>
	</nav>
</div>
<div class="mx-auto max-w-screen-2xl px-0 lg:px-2 grid grid-cols-1 lg:grid-cols-4">
	<nav class="header flex flex-col lg:pt-0 lg:col-span-1">
		<Menu />
	</nav>
	<div class="pt-0 lg:col-span-3 pb-24 px-6">
		<slot />
	</div>
</div>
