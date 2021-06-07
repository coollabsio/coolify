<script>
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { fade } from 'svelte/transition';
</script>

<div class="min-h-full text-white">
	<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
		Select a service
	</div>
</div>
<div class="text-center space-y-2 max-w-4xl mx-auto px-6" in:fade={{ duration: 100 }}>
	{#if $page.path === '/service/new'}
		<div class="flex justify-center space-x-4 font-bold pb-6">
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-blue-500 p-2 rounded bg-warmGray-800 w-48"
				on:click={() => goto('/service/new/plausible')}
			>
				<img
					alt="plausible logo"
					class="w-12 mx-auto"
					src="https://cdn.coollabs.io/assets/coolify/services/plausible/logo_sm.png"
				/>
				<div class="text-white">Plausible Analytics</div>
			</div>
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out transform hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-white p-2 rounded bg-warmGray-800 w-48"
				on:click={() => goto('/service/new/nocodb')}
			>
				<img
					alt="nocodb logo"
					class="w-14 mx-auto pb-2"
					src="https://cdn.coollabs.io/assets/coolify/services/nocodb/nocodb.png"
				/>
				<div class="flex-1" />
				<div class="text-white">NocoDB</div>
			</div>
		</div>
	{/if}
</div>
