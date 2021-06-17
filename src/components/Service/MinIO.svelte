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

</script>

<div class="text-left max-w-5xl mx-auto px-6" in:fade={{ duration: 100 }}>
	<div class="pb-2 pt-5 space-y-4">
		<div class="flex items-center pt-4">
			<div class="font-bold w-64 text-warmGray-400">Root User</div>
			<input class="w-full" value={service.config.generateEnvsMinIO.MINIO_ROOT_USER} disabled />
		</div>

		<div class="flex items-center">
			<div class="font-bold w-64 text-warmGray-400">Root Password</div>
			<PasswordField value={service.config.generateEnvsMinIO.MINIO_ROOT_PASSWORD} />
		</div>
	</div>
</div>
