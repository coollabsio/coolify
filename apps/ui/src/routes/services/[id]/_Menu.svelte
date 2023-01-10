<script lang="ts">
	export let service: any;
	export let template: any;
	import { page } from '$app/stores';
	import { appSession } from '$lib/store';
	import ServiceLinks from './_ServiceLinks.svelte';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded p-2 space-y-2 sticky top-4">
	<li class="menu-title">
		<span>General</span>
	</li>
	<li class="rounded">
		<ServiceLinks {template} {service} linkToDocs={true} />
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/services/${$page.params.id}`}>
		<a href={`/services/${$page.params.id}`} class="no-underline w-full"
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
			</svg>Configurations</a
		>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/services/${$page.params.id}/secrets`}
	>
		<a href={`/services/${$page.params.id}/secrets`} class="no-underline w-full"
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
					d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
				/>
				<circle cx="12" cy="11" r="1" />
				<line x1="12" y1="12" x2="12" y2="14.5" />
			</svg>Secrets</a
		>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/services/${$page.params.id}/storages`}
	>
		<a href={`/services/${$page.params.id}/storages`} class="no-underline w-full"
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
				<ellipse cx="12" cy="6" rx="8" ry="3" />
				<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
				<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
			</svg>Persistent Volumes</a
		>
	</li>
	<li class="menu-title">
		<span>Logs</span>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/services/${$page.params.id}/logs`}
	>
		<a
			href={`/services/${$page.params.id}/logs`}
			class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="h-6 w-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<line x1="3" y1="6" x2="3" y2="19" />
				<line x1="12" y1="6" x2="12" y2="19" />
				<line x1="21" y1="6" x2="21" y2="19" />
			</svg>Service</a
		>
	</li>
	{#if $appSession.isAdmin}
	<li class="menu-title">
		<span>Advanced</span>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/services/${$page.params.id}/danger`}
	>
		<a href={`/services/${$page.params.id}/danger`} class="no-underline w-full"
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
				<path d="M12 9v2m0 4v.01" />
				<path
					d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"
				/>
			</svg>Danger Zone</a
		>
	</li>
	{/if}
</ul>
