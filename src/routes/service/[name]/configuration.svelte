<script>
	import { fade } from 'svelte/transition';
	import { toast } from '@zerodevx/svelte-toast';

	import { page, session } from '$app/stores';
	import { request } from '$lib/fetch';
	import { goto } from '$app/navigation';
	import Loading from '$components/Loading.svelte';
	import Plausible from '$components/Service/Plausible.svelte';
	let service = {};
	async function loadServiceConfig() {
		if ($page.params.name) {
			try {
				service = await request(`/api/v1/services/${$page.params.name}`, $session);
			} catch (error) {
				toast.push(`Cannot find service ${$page.params.name}?!`);
				goto(`/dashboard/services`, { replaceState: true });
			}
		}
	}
	async function activate() {
		try {
			await request(`/api/v1/services/deploy/${$page.params.name}/activate`, $session, {
				method: 'PATCH',
				body: {}
			});
			toast.push(`All users are activated for Plausible.`);
		} catch (error) {
			console.log(error);
			toast.push(`Ooops, there was an error activating users for Plausible?!`);
		}
	}
</script>

{#await loadServiceConfig()}
	<Loading />
{:then}
	<div class="min-h-full text-white">
		<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
			<a
				href={service.config.baseURL}
				target="_blank"
				class="inline-flex hover:underline cursor-pointer px-2"
			>
				<div>{$page.params.name === 'plausible' ? 'Plausible Analytics' : $page.params.name}</div>
				<div class="px-4">
					{#if $page.params.name === 'plausible'}
						<img
							alt="plausible logo"
							class="w-6 mx-auto"
							src="https://cdn.coollabs.io/assets/coolify/services/plausible/logo_sm.png"
						/>
					{/if}
				</div>
			</a>
		</div>
	</div>
	<div class="space-y-2 max-w-4xl mx-auto px-6" in:fade={{ duration: 100 }}>
		<div class="block text-center py-4">
			{#if $page.params.name === 'plausible'}
				<Plausible {service} />
			{/if}
		</div>
	</div>
{/await}
