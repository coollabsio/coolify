<script>
	import { fade } from 'svelte/transition';
	import { toast } from '@zerodevx/svelte-toast';

	import { page, session } from '$app/stores';
	import { request } from '$lib/request';
	import { goto } from '$app/navigation';
	import Loading from '$components/Loading.svelte';
	import Plausible from '$components/Service/Plausible.svelte';
	import { browser } from '$app/env';
	import CodeServer from '$components/Service/CodeServer.svelte';
	import MinIo from '$components/Service/MinIO.svelte';
	let service = {};
	async function loadServiceConfig() {
		if ($page.params.name) {

			try {
				service = await request(`/api/v1/services/${$page.params.name}`, $session);
			} catch (error) {
				if (browser) {
					toast.push(`Cannot find service ${$page.params.name}?!`);
					goto(`/dashboard/services`, { replaceState: true });
				}
			}
		}
	}

</script>

{#await loadServiceConfig()}
	<Loading />
{:then}
	<div class="min-h-full text-white">
		<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
			{#if $page.params.name === 'plausible'}
				<div>Plausible Analytics</div>
			{:else if $page.params.name === 'nocodb'}
				<div>NocoDB</div>
			{:else if $page.params.name === 'code-server'}
				<div>VSCode Server</div>
			{:else if $page.params.name === 'minio'}
				<div>MinIO</div>
			{:else if $page.params.name.match(/wp-/)}
				<div>Wordpress<span class="flex text-xs items-center justify-center">({service.config.baseURL.replace('https://','')})</span></div>
			{/if}

			<div class="px-4">
				{#if $page.params.name === 'plausible'}
					<img
						alt="plausible logo"
						class="w-6 mx-auto"
						src="https://cdn.coollabs.io/assets/coolify/services/plausible/logo_sm.png"
					/>
				{:else if $page.params.name === 'nocodb'}
					<img
						alt="nocodb logo"
						class="w-8 mx-auto"
						src="https://cdn.coollabs.io/assets/coolify/services/nocodb/nocodb.png"
					/>
				{:else if $page.params.name === 'code-server'}
					<svg class="w-8 mx-auto" viewBox="0 0 128 128">
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
				{:else if $page.params.name === 'minio'}
					<img
						alt="minio logo"
						class="w-7 mx-auto"
						src="https://cdn.coollabs.io/assets/coolify/services/minio/MINIO_Bird.png"
					/>
				{:else if $page.params.name.match(/wp-/)}
					<svg class="w-8 mx-auto" viewBox="0 0 128 128">
						<path
							fill-rule="evenodd"
							clip-rule="evenodd"
							fill="white"
							d="M64.094 126.224c34.275-.052 62.021-27.933 62.021-62.325 0-33.833-27.618-61.697-60.613-62.286C30.85.995 1.894 29.113 1.885 63.21c-.01 35.079 27.612 63.064 62.209 63.014zM63.993 4.63c32.907-.011 59.126 26.725 59.116 60.28-.011 31.679-26.925 58.18-59.092 58.187-32.771.007-59.125-26.563-59.124-59.608.002-32.193 26.766-58.848 59.1-58.859zM39.157 35.896c.538 1.793-.968 2.417-2.569 2.542-1.685.13-3.369.257-5.325.406 6.456 19.234 12.815 38.183 19.325 57.573.464-.759.655-.973.739-1.223 3.574-10.682 7.168-21.357 10.651-32.069.318-.977.16-2.271-.188-3.275-1.843-5.32-4.051-10.524-5.667-15.908-1.105-3.686-2.571-6.071-6.928-5.644-.742.073-1.648-1.524-2.479-2.349 1.005-.6 2.003-1.704 3.017-1.719a849.593 849.593 0 0126.618.008c1.018.017 2.016 1.15 3.021 1.765-.88.804-1.639 2.01-2.668 2.321-1.651.498-3.482.404-5.458.58l19.349 57.56c2.931-9.736 5.658-18.676 8.31-27.639 2.366-8.001.956-15.473-3.322-22.52-1.286-2.119-2.866-4.175-3.595-6.486-.828-2.629-1.516-5.622-1.077-8.259.745-4.469 4.174-6.688 8.814-7.113C74.333.881 34.431 9.317 19.728 34.922c5.66-.261 11.064-.604 16.472-.678 1.022-.013 2.717.851 2.957 1.652zm10.117 77.971c-.118.345-.125.729-.218 1.302 10.943 3.034 21.675 2.815 32.659-.886l-16.78-45.96c-5.37 15.611-10.52 30.575-15.661 45.544zm-8.456-2.078l-25.281-69.35c-11.405 22.278-2.729 56.268 25.281 69.35zm76.428-44.562c.802-10.534-2.832-25.119-5.97-27.125-.35 3.875-.106 8.186-1.218 12.114-2.617 9.255-5.817 18.349-8.899 27.468-3.35 9.912-6.832 19.779-10.257 29.666 16.092-9.539 24.935-23.618 26.344-42.123z"
						/>
					</svg>
				{/if}
			</div>
			<a target="_blank" class="icon mx-2" href={service.config.baseURL}>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					fill="none"
					viewBox="0 0 24 24"
					stroke="currentColor"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
					/>
				</svg></a
			>
		</div>
	</div>
	<div class="space-y-2 max-w-4xl mx-auto px-6" in:fade={{ duration: 100 }}>
		<div class="block text-center py-4">
			{#if $page.params.name === 'plausible'}
				<Plausible {service} />
			{:else if $page.params.name === 'nocodb'}
				<div class="font-bold">Nothing to show here. Enjoy using NocoDB!</div>
			{:else if $page.params.name === 'code-server'}
				<CodeServer {service} />
			{:else if $page.params.name === 'minio'}
				<MinIo {service} />
			{:else if $page.params.name.match(/wp-/)}
				<div class="font-bold">Nothing to show here. Enjoy using WordPress!</div>
			{/if}
		</div>
	</div>
{/await}
