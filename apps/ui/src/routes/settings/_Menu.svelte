<script lang="ts">
	import { page } from '$app/stores';
	import { appSession } from '$lib/store';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded p-2 space-y-2">
	<li class="menu-title">
		<span>General</span>
	</li>
	{#if $appSession.teamId === '0'}
		<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/coolify`}>
			<a href={`/settings/coolify`} class="no-underline w-full"
				><svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path
						d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5"
					/>
				</svg>Coolify Settings</a
			>
		</li>
	{/if}
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/docker`}>
		<a href={`/settings/docker`} class="no-underline w-full">
			<svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path
					d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
				/>
				<path d="M5 10h3v3h-3z" />
				<path d="M8 10h3v3h-3z" />
				<path d="M11 10h3v3h-3z" />
				<path d="M8 7h3v3h-3z" />
				<path d="M11 7h3v3h-3z" />
				<path d="M11 4h3v3h-3z" />
				<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
				<line x1="10" y1="16" x2="10" y2="16.01" />
			</svg>Docker Registries</a
		>
	</li>

	<li class="menu-title">
		<span>Keys & Certificates</span>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/ssh`}>
		<a href={`/settings/ssh`} class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<circle cx="8" cy="15" r="4" />
				<line x1="10.85" y1="12.15" x2="19" y2="4" />
				<line x1="18" y1="5" x2="20" y2="7" />
				<line x1="15" y1="8" x2="17" y2="10" />
			</svg>SSH Keys</a
		>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/settings/certificates`}>
		<a href={`/settings/certificates`} class="no-underline w-full">
			<svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path
					d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
				/>
				<circle cx="12" cy="11" r="1" />
				<line x1="12" y1="12" x2="12" y2="14.5" />
			</svg>SSL Certificates</a
		>
	</li>
</ul>
