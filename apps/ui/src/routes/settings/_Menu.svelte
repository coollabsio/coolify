<script lang="ts">
	import { page } from '$app/stores';
	import { appSession } from '$lib/store';
</script>

<div class="flex flex-col pt-4 space-y-6 w-96 px-20">
	{#if $appSession.teamId === '0'}
		<a
			href="/settings/global"
			class="sub-menu no-underline"
			class:sub-menu-active={$page.routeId === 'settings/global'}
		>
			Global Settings
		</a>
	{/if}
	<a
		href="/settings/ssh-keys"
		class="sub-menu no-underline"
		class:sub-menu-active={$page.routeId === 'settings/ssh-keys'}>SSH Keys</a
	>
</div>
