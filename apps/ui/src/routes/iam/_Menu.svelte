<script lang="ts">
	import { appSession } from '$lib/store';
	import { page } from '$app/stores';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded p-2 space-y-2 sticky top-4">
    {#if $appSession.pendingInvitations.length > 0}
    <li class="rounded" class:bg-coollabs={$page.url.pathname === `/iam/pending`}>
		<a href={`/iam/pending`} class="no-underline w-full"
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
			</svg>Pending Invitations</a
		>
	</li>
    {/if}
	<li class="menu-title">
		<span>Configuration</span>
	</li>

	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/iam`}>
		<a href={`/iam`} class="no-underline w-full"
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
			</svg>Accounts</a
		>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/iam/teams`}>
		<a href={`/iam/teams`} class="no-under  line w-full"
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
			</svg>Teams</a
		>
	</li>
</ul>
