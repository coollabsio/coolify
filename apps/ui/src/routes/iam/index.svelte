<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/iam`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let account: any;
	export let accounts: any;

	import { appSession } from '$lib/store';
	import { get, post } from '$lib/api';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import Account from './_Account.svelte';
	let search = '';
	let searchResults: any = [];

	function searchAccount() {
		searchResults = accounts.filter((account: { email: string | string[] }) => {
			return account.email.includes(search);
		});
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2 items-center">
			<div class="title font-bold pb-3">
				{$appSession.userId === '0' && $appSession.teamId === '0' ? 'Accounts' : 'Your account'}
			</div>
		</div>
	</div>
</div>

{#if $appSession.userId === '0' && $appSession.teamId === '0'}
	<div class="w-full grid gap-2">
		<input
			class="input w-full mb-4"
			bind:value={search}
			on:input={searchAccount}
			placeholder="Search for account..."
		/>
		<div class="flex flex-col pb-2 space-y-4 lg:space-y-2">
			{#if searchResults.length > 0}
				{#each searchResults as account}
					<Account {account} {accounts} />
				{/each}
			{:else if searchResults.length === 0 && search !== ''}
				<div>Nothing found.</div>
			{:else}
				{#each accounts as account}
					<Account {account} {accounts} />
				{/each}
			{/if}
		</div>
	</div>
{:else}
	<Account {account} />
{/if}
