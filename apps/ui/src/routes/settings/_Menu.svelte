<script lang="ts">
	import { page } from '$app/stores';
	import { appSession } from '$lib/store';
</script>

<ul class="menu bg-coolgray-200 rounded">
	{#if $appSession.teamId === '0'}
		<li
			class="hover:bg-coollabs duration-150"
			class:bordered={$page.url.pathname === '/settings/coolify'}
			class:bg-coolgray-500={$page.url.pathname === '/settings/coolify'}
		>
			<a href="/settings/coolify" class="no-underline w-full">Coolify Settings</a>
		</li>
	{/if}

	<li
		class="hover:bg-coollabs duration-150"
		class:bordered={$page.url.pathname === '/settings/ssh'}
		class:bg-coolgray-500={$page.url.pathname === '/settings/ssh'}
	>
		<a href="/settings/ssh" class="no-underline w-full">SSH Keys</a>
	</li>
	<li
		class="hover:bg-coollabs duration-150"
		class:bordered={$page.url.pathname === '/settings/certificates'}
		class:bg-coolgray-400={$page.url.pathname === '/settings/certificates'}
	>
		<a href="/settings/certificates" class="no-underline w-full">SSL Certificates</a>
	</li>
</ul>
