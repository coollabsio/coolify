<script lang="ts">
	import { page } from '$app/stores';
	import { appSession } from '$lib/store';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded-box p-2 space-y-2">
	<li class="menu-title">
		<span>General</span>
	</li>
	{#if $appSession.teamId === '0'}
		<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/coolify`}>
			<a href={`/settings/coolify`} class="no-underline w-full">Coolify Settings</a>
		</li>
	{/if}
	<li class="menu-title">
		<span>Keys & Certificates</span>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/ssh`}>
		<a href={`/settings/ssh`} class="no-underline w-full">SSH Keys</a>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/certificates`}>
		<a href={`/settings/certificates`} class="no-underline w-full">SSL Certificates</a>
	</li>
</ul>
