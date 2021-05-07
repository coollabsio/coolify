<script>
	import { goto } from '$app/navigation';

	import { page, session } from '$app/stores';
	import Loading from '$components/Loading.svelte';
	import { request } from '$lib/fetch';
	import { initialNewService, newService } from '$store';

	import { toast } from '@zerodevx/svelte-toast';
	import { onDestroy } from 'svelte';

	async function checkService() {
		try {
			await request(`/api/v1/services/${$page.params.type}`, $session);
			goto(`/dashboard/services`, { replaceState: true });
			toast.push(
				`${
					$page.params.type === 'plausible' ? 'Plausible Analytics' : $page.params.type
				} already deployed.`
			);
		} catch (error) {
			//
		}
	}
	onDestroy(() => {
		$newService = JSON.parse(JSON.stringify(initialNewService));
	});
</script>

{#await checkService()}
	<Loading />
{:then}
	<div class="text-white">
		<slot />
	</div>
{/await}
