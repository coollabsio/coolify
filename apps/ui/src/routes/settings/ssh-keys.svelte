<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		try {
			return {
				props: {
					...stuff
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
	export let sshKeys: any;
	import { del, post } from '$lib/api';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import Menu from './_Menu.svelte';

	let loading = {
		save: false
	};
	let isModalActive = false;

	let newSSHKey = {
		name: null,
		privateKey: null
	};

	async function handleSubmit() {
		try {
			await post(`/settings/sshKey`, { ...newSSHKey });
			return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function deleteSSHKey(id: string) {
		const sure = confirm('Are you sure you would like to delete this SSH key?');
		if (sure) {
			try {
				if (!id) return;
				await del(`/settings/sshKey`, { id });
				return window.location.reload();
			} catch (error) {
				errorNotification(error);
				return false;
			}
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.settings')}</div>
</div>
<div class="mx-auto w-full">
	<div class="flex flex-row">
		<Menu />
		<div class="grid grid-flow-row gap-2 py-4">
			<div class="flex space-x-1 pb-6">
				<div class="title font-bold">SSH Keys</div>
				<button
					on:click={() => (isModalActive = true)}
					class="btn btn-sm bg-settings text-black"
					disabled={loading.save}>New SSH Key</button
				>
			</div>
			<div class="grid grid-flow-col gap-2 px-10">
				{#if sshKeys.length === 0}
					<div class="text-sm ">No SSH keys found</div>
				{:else}
					{#each sshKeys as key}
						<div class="box-selection group relative">
							<div class="text-xl font-bold">{key.name}</div>
							<div class="py-3 text-stone-600">Added on {key.createdAt}</div>
							<button on:click={() => deleteSSHKey(key.id)} class="btn btn-sm bg-error">Delete</button>
						</div>
					{/each}
				{/if}
			</div>
		</div>
	</div>
</div>
{#if isModalActive}
	<div class="relative z-10" aria-labelledby="modal-title" role="dialog" aria-modal="true"> 
		<div class="fixed inset-0 bg-coolgray-500 bg-opacity-75 transition-opacity" />
		<div class="fixed z-10 inset-0 overflow-y-auto text-white">
			<div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">
				<form
					on:submit|preventDefault={handleSubmit}
					class="relative bg-coolblack rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg sm:w-full sm:p-6  border border-coolgray-500"
				>
					<div class="hidden sm:block absolute top-0 right-0 pt-4 pr-4">
						<button
							on:click={() => (isModalActive = false)}
							type="button"
							class=" rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
						>
							<span class="sr-only">Close</span>
							<svg
								class="h-6 w-6"
								xmlns="http://www.w3.org/2000/svg"
								fill="none"
								viewBox="0 0 24 24"
								stroke-width="2"
								stroke="currentColor"
								aria-hidden="true"
							>
								<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
					</div>
					<div class="sm:flex sm:items-start">
						<div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
							<h3 class="text-lg leading-6 font-medium pb-4" id="modal-title">New SSH Key</h3>
							<div class="text-xs text-stone-400">Add an SSH key to your Coolify instance.</div>
							<div class="mt-2">
								<label for="privateKey" class="pb-2">Key</label>
								<textarea
									id="privateKey"
									required
									bind:value={newSSHKey.privateKey}
									class="w-full"
									rows={15}
								/>
							</div>
							<div class="mt-2">
								<label for="name" class="pb-2">Name</label>
								<input id="name" required bind:value={newSSHKey.name} class="w-full" />
							</div>
						</div>
					</div>
					<div class="mt-5 flex space-x-4 justify-end">
						<button type="submit" class="bg-green-600 hover:bg-green-500">Save</button>
						<button on:click={() => (isModalActive = false)} type="button" class="">Cancel</button>
					</div>
				</form>
			</div>
		</div>
	</div>
{/if}
