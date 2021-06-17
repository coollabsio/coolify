<script>
	import { fade } from 'svelte/transition';
	import { toast } from '@zerodevx/svelte-toast';
	import Loading from '../Loading.svelte';
	import Tooltip from '$components/Tooltip.svelte';
	import { request } from '$lib/request';
	import { page, session } from '$app/stores';
	import PasswordField from '$components/PasswordField.svelte';
	import { browser } from '$app/env';
	export let service;

	async function getPassword() {
		try {
			const { password } = await request(`/api/v1/services/deploy/${$page.params.name}/password`, $session);
            service.config.password = password
		} catch (error) {
			console.log(error);
			browser && toast.push(`Ooops, there was an error activating users for VSCode Server?!`);
		}
	}

</script>

{#await getPassword()}
	<Loading />
{:then}
	<div class="text-left max-w-5xl mx-auto px-6" in:fade={{ duration: 100 }}>
		<div class="pb-2 pt-5 space-y-4">
			<div class="flex items-center">
				<div class="font-bold w-64 text-warmGray-400">Password</div>
				<PasswordField value={service.config.password} />
			</div>
		</div>
	</div>
{/await}
