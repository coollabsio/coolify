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
			{/if}
		</div>
	</div>
{/await}
