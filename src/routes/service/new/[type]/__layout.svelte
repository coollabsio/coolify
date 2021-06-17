<script>
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { page, session } from '$app/stores';
	import Loading from '$components/Loading.svelte';
	import { request } from '$lib/request';
	import { initialNewService, newService } from '$store';

	import { toast } from '@zerodevx/svelte-toast';
	import { onDestroy } from 'svelte';

	async function checkService() {
		try {
			const data = await request(`/api/v1/services/${$page.params.type}`, $session);
			if (data?.success) {
				if (browser) {
					goto(`/service/${$page.params.type}/configuration`, { replaceState: true });
					toast.push(
						`Service already deployed.`
					);
				}
			}
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
