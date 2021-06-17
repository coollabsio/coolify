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
				class="text-center flex-col items-center cursor-pointer ease-in-out  hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-blue-500 p-2 rounded bg-warmGray-800 w-48"
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
				class="text-center flex-col items-center cursor-pointer ease-in-out  hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-white p-2 rounded bg-warmGray-800 w-48"
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
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out  hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-green-500 p-2 rounded bg-warmGray-800 w-48"
				on:click={() => goto('/service/new/code-server')}
			>
				<svg class="w-14 mx-auto pb-2" viewBox="0 0 128 128">
					<path
						d="M3.656 45.043s-3.027-2.191.61-5.113l8.468-7.594s2.426-2.559 4.989-.328l78.175 59.328v28.45s-.039 4.468-5.757 3.976zm0 0"
						fill="#2489ca"
					/><path
						d="M23.809 63.379L3.656 81.742s-2.07 1.543 0 4.305l9.356 8.527s2.222 2.395 5.508-.328l21.359-16.238zm0 0"
						fill="#1070b3"
					/><path
						d="M59.184 63.531l36.953-28.285-.239-28.297S94.32.773 89.055 3.99L39.879 48.851zm0 0"
						fill="#0877b9"
					/><path
						d="M90.14 123.797c2.145 2.203 4.747 1.48 4.747 1.48l28.797-14.222c3.687-2.52 3.171-5.645 3.171-5.645V20.465c0-3.735-3.812-5.024-3.812-5.024L98.082 3.38c-5.453-3.379-9.027.61-9.027.61s4.593-3.317 6.843 2.96v112.317c0 .773-.164 1.53-.492 2.214-.656 1.332-2.086 2.57-5.504 2.051zm0 0"
						fill="#3c99d4"
					/>
				</svg>
				<div class="flex-1" />
				<div class="text-white">VSCode Server</div>
			</div>
			<div
				class="text-center flex-col items-center cursor-pointer ease-in-out  hover:scale-105 duration-100 border-2 border-dashed border-transparent hover:border-red-500 p-2 rounded bg-warmGray-800 w-48"
				on:click={() => goto('/service/new/minio')}
			>
				<img
					alt="minio logo"
					class="w-7 mx-auto pb-2"
					src="https://cdn.coollabs.io/assets/coolify/services/minio/MINIO_Bird.png"
				/>
				<div class="flex-1" />
				<div class="text-white">MinIO</div>
			</div>
		</div>
	{/if}
</div>
