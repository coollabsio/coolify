<script lang="ts">
	import { appSession } from '$lib/store';
	import { page } from '$app/stores';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded p-2 space-y-2 sticky top-4">
	{#if $appSession.pendingInvitations.length > 0}
		<li class="menu-title">
			<span>IAM</span>
		</li>
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
					<path d="M10 21v-6.5a3.5 3.5 0 0 0 -7 0v6.5h18v-6a4 4 0 0 0 -4 -4h-10.5" />
					<path d="M12 11v-8h4l2 2l-2 2h-4" />
					<path d="M6 15h1" />
				</svg>Pending Invitations</a
			>
		</li>
	{/if}
	<li class="menu-title">
		<span>IAM</span>
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
				<circle cx="12" cy="7" r="4" />
				<path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
			</svg>{$appSession.userId === '0' && $appSession.teamId === '0' ? 'Accounts' : 'Account'}</a
		>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname.startsWith(`/iam/teams`)}>
		<a href={`/iam/teams`} class="no-underline w-full"
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
				<circle cx="9" cy="7" r="4" />
				<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
				<path d="M16 3.13a4 4 0 0 1 0 7.75" />
				<path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
			</svg>Teams</a
		>
	</li>
</ul>
